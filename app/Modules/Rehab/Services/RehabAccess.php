<?php

namespace App\Modules\Rehab\Services;

use App\Models\User;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Rehab\Models\RehabPlan;

/**
 * Două niveluri de vizibilitate pentru un plan de recuperare:
 *  - logistica (proceduri, număr de ședințe, programări, evidență): rehab.view — inclusiv recepția;
 *  - conținutul clinic (obiective, observații, evoluție, concluzie): medical.view + relație de îngrijire.
 */
class RehabAccess
{
    public function __construct(private readonly CareAccess $care) {}

    public function canSeeClinical(User $user, RehabPlan $plan): bool
    {
        return $user->can('medical.view') && $this->care->canAccessMedical($user, $plan->patient);
    }

    public function authorizeClinical(User $user, RehabPlan $plan, string $permission): void
    {
        abort_unless($user->can($permission), 403, 'Nu aveți permisiunea necesară.');
        abort_unless($this->care->canAccessMedical($user, $plan->patient), 403, 'Nu aveți o relație de îngrijire cu acest pacient.');
    }
}
