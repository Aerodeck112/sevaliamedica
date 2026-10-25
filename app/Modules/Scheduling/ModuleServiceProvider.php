<?php

namespace App\Modules\Scheduling;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Patients\Support\PatientMergeRegistry;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;
use App\Modules\Scheduling\Dashboard\MyPatientsTodayWidget;
use App\Modules\Scheduling\Dashboard\ReceptionTodayWidget;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Policies\AppointmentPolicy;
use App\Modules\Scheduling\Services\AppointmentIntakeService;
use App\Modules\Scheduling\Support\AppointmentCareEvidence;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'scheduling';
    }

    public function label(): string
    {
        return 'Programări';
    }

    public function permissions(): array
    {
        return [
            'scheduling.view' => 'Vede calendarul și programările',
            'scheduling.manage' => 'Creează, mută, confirmă și anulează programări',
            'scheduling.attend' => 'Marchează sosirea, începerea și finalizarea propriilor programări',
            'scheduling.override' => 'Programează în afara programului de lucru (cu motiv)',
            'scheduling.requests' => 'Preia solicitările de programare venite de pe site',
            'scheduling.settings' => 'Modifică regulile de programare și de acces (setări auditate)',
            'schedules.manage' => 'Administrează programul de lucru, concediile și închiderile',
            'waitlist.manage' => 'Administrează lista de așteptare și ofertele de intervale',
            'services.view' => 'Vede serviciile efectuate ale pacienților',
            'services.record' => 'Înregistrează serviciile efectuate',
            'services.correct' => 'Anulează / corectează servicii efectuate (cu motiv)',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::RECEPTIE => ['scheduling.view', 'scheduling.manage', 'scheduling.override', 'scheduling.requests', 'schedules.manage', 'waitlist.manage', 'services.view', 'services.record', 'services.correct'],
            Roles::MANAGER => array_keys($this->permissions()),
            Roles::MEDIC => ['scheduling.view', 'scheduling.attend', 'services.view', 'services.record', 'services.correct'],
            Roles::TERAPEUT => ['scheduling.view', 'scheduling.attend', 'services.view', 'services.record'],
            Roles::ASISTENT => ['scheduling.view', 'scheduling.attend', 'services.view', 'services.record'],
            Roles::RESPONSABIL_CAS => ['services.view'],
            Roles::FINANCIAR => ['services.view'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Calendar', 'panou.calendar', 'scheduling.view', 'Activitate', 'calendar', 10, 'panou.calendar*'),
            new NavItem('Solicitări', 'panou.requests.index', 'scheduling.requests', 'Activitate', 'inbox', 11),
            new NavItem('Listă de așteptare', 'panou.waitlist.index', 'waitlist.manage', 'Activitate', 'clock', 12),
            new NavItem('Serii de ședințe', 'panou.series.index', 'scheduling.view', 'Activitate', 'layers', 13),
            new NavItem('Program de lucru', 'panou.schedules.index', 'schedules.manage', 'Administrare', 'clock', 36),
            new NavItem('Reguli programări', 'panou.scheduling-settings.edit', 'scheduling.settings', 'Administrare', 'settings', 37),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [new ReceptionTodayWidget, new MyPatientsTodayWidget];
    }

    public function schedule(Schedule $schedule): void
    {
        // Intrările expirate din lista de așteptare se închid (nu se șterg).
        $schedule->call(fn () => WaitlistEntry::whereIn('status', ['activ', 'oferit'])->whereNotNull('valid_until')
            ->where('valid_until', '<', now()->toDateString())->update(['status' => 'expirat']))
            ->name('scheduling:waitlist-expire')->dailyAt('01:10')->withoutOverlapping();
    }

    public function register(): void
    {
        parent::register();
        $this->app->bind(AppointmentIntake::class, AppointmentIntakeService::class);
    }

    public function boot(): void
    {
        parent::boot();
        Gate::policy(Appointment::class, AppointmentPolicy::class);

        $this->app->make(CareAccess::class)->addEvidence(new AppointmentCareEvidence);

        $tabs = $this->app->make(PatientTabRegistry::class);
        $tabs->add(new PatientTab('programari', 'Programări', 'scheduling.view', 10,
            fn (Patient $p, User $u) => view('scheduling::patient-tabs.appointments', [
                'patient' => $p,
                'appointments' => Appointment::where('patient_id', $p->id)->with(['service', 'staffMember', 'location'])->orderByDesc('starts_at')->limit(100)->get(),
                'series' => AppointmentSeries::where('patient_id', $p->id)->with('service')->latest()->get(),
                'waitlist' => WaitlistEntry::where('patient_id', $p->id)->open()->with('service')->get(),
            ]),
            badge: fn (Patient $p) => Appointment::where('patient_id', $p->id)->where('starts_at', '>=', now())->whereIn('status', [AppointmentStatus::CONFIRMATA])->count() ?: null));
        $tabs->add(new PatientTab('servicii', 'Servicii efectuate', 'services.view', 20,
            fn (Patient $p, User $u) => view('scheduling::patient-tabs.services', [
                'patient' => $p,
                'records' => ServiceRecord::where('patient_id', $p->id)->with(['service', 'staffMember', 'location', 'correctedBy'])->orderByDesc('performed_at')->get(),
            ])));

        $merge = $this->app->make(PatientMergeRegistry::class);
        $merge->add(PatientMergeRegistry::table('Programări și solicitări', 'appointments'));
        $merge->add(PatientMergeRegistry::table('Serii de ședințe', 'appointment_series'));
        $merge->add(PatientMergeRegistry::table('Listă de așteptare', 'waitlist_entries'));
        $merge->add(PatientMergeRegistry::table('Servicii efectuate', 'service_records'));
    }
}
