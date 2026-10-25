<?php

namespace App\Modules\Medical\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Support\TemplateSchema;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclul de viață al fișelor medicale. Contract pentru Rehab (reevaluări) și pentru rapoarte:
 *  - createDraft()  fișă nouă din ultima versiune publicată a unui șablon (sau text liber);
 *  - saveDraft()    salvează valorile (blocare optimistă);
 *  - finalize()     conținut imuabil + sumă de control (după reconfirmarea identității, în controller);
 *  - amend()        completare / rectificare = fișă nouă legată de cea originală, cu motiv;
 *  - abandon()      renunțare la un draft (cu motiv), fără ștergere.
 * Nicio decizie clinică nu este generată de aplicație: conținutul îl scrie exclusiv personalul medical.
 */
class RecordService
{
    public function __construct(private readonly MedicalAccess $access) {}

    public function createDraft(Patient $patient, ?MedicalTemplate $template, User $user, array $attrs = []): MedicalRecord
    {
        $this->access->authorize($user, $patient, 'medical.write');
        $staff = $this->access->staffFor($user);
        if (! $staff) {
            throw ValidationException::withMessages(['template_id' => 'Contul nu este asociat unei persoane din personalul clinicii (Administrare → Personal).']);
        }

        $version = null;
        if ($template) {
            $version = $template->latestVersion;
            if (! $version || ! $template->is_active) {
                throw ValidationException::withMessages(['template_id' => 'Șablonul nu are o versiune publicată.']);
            }
        }

        $appointmentId = $attrs['appointment_id'] ?? null;
        if ($appointmentId && ! Appointment::whereKey($appointmentId)->where('patient_id', $patient->id)->exists()) {
            throw ValidationException::withMessages(['appointment_id' => 'Programarea nu aparține acestui pacient.']);
        }

        $record = MedicalRecord::create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointmentId,
            'staff_member_id' => $staff->id,
            'specialty_id' => $template?->specialty_id ?? ($attrs['specialty_id'] ?? null),
            'template_version_id' => $version?->id,
            'rehab_plan_id' => $attrs['rehab_plan_id'] ?? null,
            'kind' => $attrs['kind'] ?? 'consultatie',
            'title' => trim((string) ($attrs['title'] ?? '')) ?: ($template?->name ?? 'Notă clinică'),
            'status' => MedicalRecord::DRAFT,
            'content' => [],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        Audit::log('medical.record_created', $record, ['tip' => $record->kind], $patient->id);

        return $record;
    }

    public function saveDraft(MedicalRecord $record, array $values, int $expectedVersion, User $user, ?string $title = null): MedicalRecord
    {
        return DB::transaction(function () use ($record, $values, $expectedVersion, $user, $title) {
            $locked = MedicalRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertEditableBy($locked, $user);
            if ((int) $locked->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'Fișa a fost modificată între timp (altă fereastră sau alt utilizator). Reîncărcați pagina înainte de a salva.']);
            }
            $locked->forceFill([
                'content' => TemplateSchema::values($locked->schema(), $values, strict: false),
                'title' => $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 190) : $locked->title,
                'version' => $locked->version + 1,
                'updated_by' => $user->id,
            ])->save();
            Audit::log('medical.record_saved', $locked, [], $locked->patient_id);

            return $locked;
        });
    }

    public function finalize(MedicalRecord $record, User $user): MedicalRecord
    {
        return DB::transaction(function () use ($record, $user) {
            $locked = MedicalRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertEditableBy($locked, $user);
            abort_unless($user->can('medical.finalize'), 403);
            if ($locked->isAmendment() && trim((string) $locked->amendment_reason) === '') {
                throw ValidationException::withMessages(['amendment_reason' => 'Motivul completării / rectificării este obligatoriu.']);
            }
            // Validare strictă: câmpurile obligatorii trebuie completate.
            TemplateSchema::values($locked->schema(), $locked->content ?? [], strict: true);

            $locked->forceFill(['status' => MedicalRecord::FINAL, 'finalized_at' => now(), 'finalized_by' => $user->id, 'updated_by' => $user->id]);
            $locked->content_hash = $locked->computeHash();
            $locked->save();
            Audit::log($locked->isAmendment() ? 'medical.record_amended' : 'medical.record_finalized', $locked, [
                'tip' => $locked->kind, 'corecteaza' => $locked->amends_id,
            ], $locked->patient_id);

            return $locked;
        });
    }

    /**
     * Pornește o completare sau o rectificare. Rectificarea preia valorile originale pentru a fi corectate;
     * completarea pornește cu un text liber gol. Originalul nu se modifică.
     */
    public function amend(MedicalRecord $original, string $kind, string $reason, User $user): MedicalRecord
    {
        if (! in_array($kind, ['completare', 'rectificare'], true)) {
            throw ValidationException::withMessages(['kind' => 'Tip de corectură necunoscut.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Descrieți motivul (minimum 10 caractere). Motivul rămâne în istoricul fișei.']);
        }
        $root = $original->root();
        if (! $root->isFinal()) {
            throw ValidationException::withMessages(['kind' => 'Doar o fișă finalizată se completează sau se rectifică. Un draft se editează direct.']);
        }
        $this->access->authorize($user, $root->patient, 'medical.amend');
        $staff = $this->access->staffFor($user);
        abort_unless($staff, 403, 'Contul nu este asociat unei persoane din personalul clinicii.');

        // Pentru rectificare pornim de la ultima versiune valabilă (rectificarea anterioară, dacă există).
        $base = $kind === 'rectificare' ? ($root->latestRectification() ?? $root) : null;

        $record = MedicalRecord::create([
            'patient_id' => $root->patient_id,
            'appointment_id' => $root->appointment_id,
            'staff_member_id' => $staff->id,
            'specialty_id' => $root->specialty_id,
            'template_version_id' => $kind === 'rectificare' ? $root->template_version_id : null,
            'rehab_plan_id' => $root->rehab_plan_id,
            'kind' => $kind,
            'title' => ($kind === 'rectificare' ? 'Rectificare: ' : 'Completare: ').$root->title,
            'status' => MedicalRecord::DRAFT,
            'content' => $base?->content ?? [],
            'amends_id' => $root->id,
            'amendment_reason' => trim($reason),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        Audit::log('medical.amendment_started', $record, ['tip' => $kind, 'corecteaza' => $root->id], $root->patient_id);

        return $record;
    }

    public function abandon(MedicalRecord $record, string $reason, User $user): MedicalRecord
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Motivul este obligatoriu.']);
        }

        return DB::transaction(function () use ($record, $reason, $user) {
            $locked = MedicalRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertEditableBy($locked, $user);
            $locked->forceFill([
                'status' => MedicalRecord::ABANDONED,
                'amendment_reason' => $locked->isAmendment() ? $locked->amendment_reason : 'Draft abandonat: '.trim($reason),
                'updated_by' => $user->id,
            ])->save();
            Audit::log('medical.record_abandoned', $locked, [], $locked->patient_id);

            return $locked;
        });
    }

    /** Un draft îl editează doar autorul său, cu relație de îngrijire activă. */
    public function assertEditableBy(MedicalRecord $record, User $user): void
    {
        if (! $record->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Fișa nu mai este draft. Folosiți „Completare” sau „Rectificare”.']);
        }
        $this->access->authorize($user, $record->patient, 'medical.write');
        $staff = $this->access->staffFor($user);
        abort_unless($staff && (int) $staff->id === (int) $record->staff_member_id, 403, 'Doar autorul poate edita sau finaliza acest draft.');
    }
}
