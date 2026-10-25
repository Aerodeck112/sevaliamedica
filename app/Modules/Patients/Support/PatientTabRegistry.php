<?php

namespace App\Modules\Patients\Support;

use App\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;

/** Registrul taburilor fișei pacientului. Modulele adaugă taburi din boot() fără a modifica modulul Patients. */
class PatientTabRegistry
{
    /** @var array<string, PatientTab> */
    private array $tabs = [];

    public function add(PatientTab $tab): void
    {
        $this->tabs[$tab->key] = $tab;
    }

    /** @return list<PatientTab> */
    public function visibleFor(User $user, Patient $patient, CareAccess $care): array
    {
        $tabs = array_filter($this->tabs, function (PatientTab $tab) use ($user, $patient, $care) {
            if ($tab->permission !== null && ! $user->can($tab->permission)) {
                return false;
            }

            return ! $tab->requiresCare || $care->canAccessMedical($user, $patient);
        });
        usort($tabs, fn (PatientTab $a, PatientTab $b) => $a->order <=> $b->order);

        return $tabs;
    }

    public function get(string $key): ?PatientTab
    {
        return $this->tabs[$key] ?? null;
    }
}
