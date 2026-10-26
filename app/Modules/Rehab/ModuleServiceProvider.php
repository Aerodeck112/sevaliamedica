<?php

namespace App\Modules\Rehab;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Patients\Support\PatientMergeRegistry;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;
use App\Modules\Rehab\Dashboard\TherapistWidget;
use App\Modules\Rehab\Models\PatientPackage;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Services\SessionProgress;
use App\Modules\Rehab\Support\RehabCareEvidence;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'rehab';
    }

    public function label(): string
    {
        return 'Recuperare medicală';
    }

    public function permissions(): array
    {
        return [
            'rehab.view' => 'Vede planurile de recuperare (proceduri, ședințe, programări); conținutul clinic cere și relație de îngrijire',
            'rehab.plan' => 'Întocmește planuri de recuperare (draft) pentru pacienții în îngrijire',
            'rehab.approve' => 'Aprobă, prelungește, suspendă și încheie planuri (medic)',
            'rehab.schedule' => 'Programează seriile de ședințe ale planurilor aprobate',
            'rehab.sessions' => 'Marchează ședințele efectuate (cu observații și evoluție)',
            'rehab.packages' => 'Configurează tipurile de pachete de ședințe',
            'rehab.packages.assign' => 'Atribuie pachete de ședințe pacienților',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::MEDIC => ['rehab.view', 'rehab.plan', 'rehab.approve', 'rehab.schedule', 'rehab.sessions'],
            Roles::TERAPEUT => ['rehab.view', 'rehab.sessions'],
            Roles::ASISTENT => ['rehab.view', 'rehab.sessions'],
            Roles::RECEPTIE => ['rehab.view', 'rehab.schedule', 'rehab.packages.assign'],
            Roles::MANAGER => ['rehab.view', 'rehab.schedule', 'rehab.packages', 'rehab.packages.assign'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Planuri recuperare', 'panou.rehab.plans.index', 'rehab.view', 'Medical', 'activity', 20),
            new NavItem('Ședințe de marcat', 'panou.rehab.sessions.index', 'rehab.sessions', 'Medical', 'check', 21),
            new NavItem('Pachete ședințe', 'panou.rehab.packages.index', 'rehab.packages', 'Administrare', 'layers', 38),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [new TherapistWidget];
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(CareAccess::class)->addEvidence(new RehabCareEvidence);

        $this->app->make(PatientTabRegistry::class)->add(new PatientTab('recuperare', 'Recuperare', 'rehab.view', 40,
            fn (Patient $p, User $u) => view('rehab::patient-tab', [
                'patient' => $p,
                'user' => $u,
                'plans' => RehabPlan::where('patient_id', $p->id)->with(['items.service', 'physician'])->latest()->get(),
                'packages' => PatientPackage::where('patient_id', $p->id)->with('definition')->latest()->get(),
                'progress' => app(SessionProgress::class),
            ]),
            badge: fn (Patient $p) => RehabPlan::where('patient_id', $p->id)->open()->count() ?: null));

        $merge = $this->app->make(PatientMergeRegistry::class);
        $merge->add(PatientMergeRegistry::table('Planuri de recuperare', 'rehab_plans'));
        $merge->add(PatientMergeRegistry::table('Pachete de ședințe', 'patient_packages'));
    }
}
