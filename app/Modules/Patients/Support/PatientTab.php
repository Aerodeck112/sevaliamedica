<?php

namespace App\Modules\Patients\Support;

use App\Models\User;
use App\Modules\Patients\Models\Patient;
use Closure;
use Illuminate\Contracts\View\View;

/**
 * Un tab din fișa pacientului, declarat de un modul:
 *
 *     app(PatientTabRegistry::class)->add(new PatientTab(
 *         key: 'fise', label: 'Fișe medicale', permission: 'medical.view', order: 30,
 *         render: fn (Patient $p, User $u) => view('medical::patient-tab', [...]),
 *         requiresCare: true,
 *     ));
 *
 * requiresCare = true: tabul apare numai dacă CareAccess::canAccessMedical() permite (date medicale).
 */
final class PatientTab
{
    /**
     * @param  Closure(Patient, User): View  $render
     * @param  Closure(Patient): (int|string|null)|null  $badge  text scurt afișat lângă titlu (ex. număr)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $permission,
        public readonly int $order,
        public readonly Closure $render,
        public readonly bool $requiresCare = false,
        public readonly ?Closure $badge = null,
    ) {}
}
