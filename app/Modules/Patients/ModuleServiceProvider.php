<?php

namespace App\Modules\Patients;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Patients\Support\PatientMergeRegistry;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'patients';
    }

    public function label(): string
    {
        return 'Pacienți';
    }

    public function permissions(): array
    {
        return [
            'patients.view' => 'Consultă fișele administrative ale pacienților (identificare, contact, programări)',
            'patients.create' => 'Creează fișe de pacient',
            'patients.edit' => 'Modifică datele administrative și reprezentanții',
            'patients.reveal_identifiers' => 'Afișează CNP-ul complet (acțiune auditată)',
            'patients.merge' => 'Unește fișe duplicate (cu reconfirmare și motiv)',
            'patients.care_relations' => 'Desemnează relații explicite de îngrijire',
            'patients.break_glass' => 'Acces de urgență la datele medicale fără relație de îngrijire (cu motiv, auditat)',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::RECEPTIE => ['patients.view', 'patients.create', 'patients.edit', 'patients.reveal_identifiers'],
            Roles::MEDIC => ['patients.view', 'patients.create', 'patients.edit', 'patients.reveal_identifiers', 'patients.break_glass'],
            Roles::TERAPEUT => ['patients.view'],
            Roles::ASISTENT => ['patients.view', 'patients.edit'],
            Roles::MANAGER => ['patients.view', 'patients.create', 'patients.edit', 'patients.reveal_identifiers', 'patients.merge', 'patients.care_relations'],
            Roles::RESPONSABIL_CAS => ['patients.view', 'patients.reveal_identifiers'],
            Roles::FINANCIAR => ['patients.view'],
        ];
    }

    public function navigation(): array
    {
        return [new NavItem('Pacienți', 'panou.patients.index', 'patients.view', 'Activitate', 'users', 20)];
    }

    public function register(): void
    {
        parent::register();
        $this->app->singleton(PatientTabRegistry::class);
        $this->app->singleton(PatientMergeRegistry::class);
        $this->app->singleton(CareAccess::class);
    }

    public function boot(): void
    {
        parent::boot();

        $tabs = $this->app->make(PatientTabRegistry::class);
        $tabs->add(new PatientTab('date', 'Date administrative', 'patients.view', 0,
            fn (Patient $p, User $u) => view('patients::tabs.overview', ['patient' => $p->load('representatives'), 'user' => $u])));
        $tabs->add(new PatientTab('ingrijire', 'Relații de îngrijire', 'patients.view', 90,
            fn (Patient $p, User $u) => view('patients::tabs.care', [
                'patient' => $p,
                'relations' => $p->careRelations()->with('staffMember')->latest('valid_from')->get(),
                'user' => $u,
            ]),
            badge: fn (Patient $p) => $p->careRelations()->current()->count() ?: null));

        $merge = $this->app->make(PatientMergeRegistry::class);
        $merge->add(PatientMergeRegistry::table('Reprezentanți legali', 'patient_representatives'));
        $merge->add(PatientMergeRegistry::table('Relații de îngrijire', 'care_relations'));
    }
}
