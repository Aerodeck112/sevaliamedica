<?php

namespace App\Modules\Patients\Services;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\BreakGlassAccess;
use App\Modules\Patients\Models\CareRelation;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\CareEvidence;
use Illuminate\Validation\ValidationException;

/**
 * Decide dacă un utilizator poate accesa DATELE MEDICALE ale unui pacient (fișe, documente, recuperare).
 *
 * Accesul cere, pe lângă permisiunea modulului, una dintre:
 *  1. o relație de îngrijire dovedită de un modul (ex. programare cu pacientul în fereastra configurată);
 *  2. o relație explicită în `care_relations`, valabilă azi;
 *  3. un acces de urgență („break-glass”) activ, cu motiv, limitat în timp și auditat.
 *
 * Datele administrative (identificare, contact, programări) sunt guvernate doar de permisiunile patients.*.
 */
class CareAccess
{
    /** @var list<CareEvidence> */
    private array $evidence = [];

    public function addEvidence(CareEvidence $evidence): void
    {
        $this->evidence[] = $evidence;
    }

    /** Evaluat la fiecare apel (fără cache): o relație încheiată sau expirată se aplică imediat. */
    public function canAccessMedical(User $user, Patient $patient): bool
    {
        return $this->resolve($user, $patient) !== null;
    }

    /** Motivul pentru care accesul e permis (relatie | programare | urgenta) sau null. */
    public function resolve(User $user, Patient $patient): ?string
    {
        if ($this->activeBreakGlass($user, $patient)) {
            return 'urgenta';
        }

        $staff = $this->staffFor($user);
        if (! $staff || ! $staff->is_active) {
            return null;
        }

        if (CareRelation::where('patient_id', $patient->id)->where('staff_member_id', $staff->id)->current()->exists()) {
            return 'relatie';
        }

        foreach ($this->evidence as $evidence) {
            if ($evidence->establishes($staff, $patient)) {
                return 'dovada_modul';
            }
        }

        return null;
    }

    public function activeBreakGlass(User $user, Patient $patient): ?BreakGlassAccess
    {
        return BreakGlassAccess::where('user_id', $user->id)->where('patient_id', $patient->id)
            ->where('expires_at', '>', now())->latest('id')->first();
    }

    /**
     * Acordă acces de urgență. Necesită permisiunea patients.break_glass și un motiv de cel puțin 15 caractere.
     */
    public function grantBreakGlass(User $user, Patient $patient, string $reason): BreakGlassAccess
    {
        abort_unless($user->can('patients.break_glass'), 403);
        if (mb_strlen(trim($reason)) < 15) {
            throw ValidationException::withMessages(['reason' => 'Descrieți motivul accesului de urgență (minimum 15 caractere).']);
        }

        $access = BreakGlassAccess::create([
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'reason' => trim($reason),
            'expires_at' => now()->addMinutes(self::setting('break_glass_minutes')),
        ]);
        // Motivul e un text liber care poate conține date medicale: îl păstrăm în tabelă, nu în contextul de audit.
        Audit::log('patients.break_glass', $access, ['expira' => $access->expires_at->toIso8601String()], $patient->id);

        return $access;
    }

    /**
     * Setările de acces (editabile din panou, cheile patients.*); implicit din config/patients.php.
     * Chei: care_window_past_days, care_window_future_days, break_glass_minutes.
     */
    public static function setting(string $key): int
    {
        return (int) app(Settings::class)->get('patients.'.$key, config('patients.'.$key));
    }

    public function staffFor(User $user): ?StaffMember
    {
        return StaffMember::where('user_id', $user->id)->first();
    }
}
