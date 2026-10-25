<?php

namespace App\Modules\Medical\Services;

use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;

/**
 * Regula unică pentru datele medicale: permisiunea modulului ȘI relația de îngrijire (CareAccess),
 * evaluate pe server la fiecare cerere. Recepția și administratorul tehnic nu au permisiunile medical.*.
 */
class MedicalAccess
{
    public function __construct(private readonly CareAccess $care) {}

    public function allows(User $user, Patient $patient, string $permission = 'medical.view'): bool
    {
        return $user->can($permission) && $this->care->canAccessMedical($user, $patient);
    }

    /** 403 dacă nu e permis; motivul accesului (relatie / dovada_modul / urgenta) pentru audit. */
    public function authorize(User $user, Patient $patient, string $permission = 'medical.view'): string
    {
        abort_unless($user->can($permission), 403, 'Nu aveți permisiunea necesară.');
        $reason = $this->care->resolve($user, $patient);
        abort_if($reason === null, 403, 'Nu aveți o relație de îngrijire cu acest pacient. Pentru o urgență folosiți „Acces de urgență” din fișa pacientului.');

        return $reason;
    }

    public function staffFor(User $user): ?StaffMember
    {
        return $this->care->staffFor($user);
    }
}
