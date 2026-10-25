<?php

namespace Tests\Feature\Scheduling;

use App\Core\Settings\Settings;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Services\SchedulingSettings;
use Database\Seeders\Demo\DemoSeeder;
use Tests\TestCase;

/** Toate ecranele modulului se randează cu datele demonstrative, pentru rolurile potrivite. */
class ScreensTest extends TestCase
{
    public function test_reception_screens_render_with_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAsRole(Roles::RECEPTIE);
        $location = \App\Modules\Clinic\Models\Location::where('slug', 'cartier-unirii')->first();
        $appointment = Appointment::where('status', 'confirmata')->whereNotNull('patient_id')->first();
        $request = Appointment::where('status', 'solicitare')->first();
        $patient = Patient::first();

        foreach ([
            route('panou.dashboard') => 'Recepție — azi',
            route('panou.calendar', ['location_id' => $location->id]) => 'Calendar',
            route('panou.calendar', ['location_id' => $location->id, 'view' => 'saptamana']) => 'Calendar',
            route('panou.calendar', ['location_id' => $location->id, 'group' => 'sali']) => 'Sala',
            route('panou.appointments.create', ['patient_id' => $patient->id]) => 'Programare nouă',
            route('panou.appointments.show', $appointment) => 'Mută programarea',
            route('panou.requests.index') => 'Solicitări noi',
            route('panou.requests.show', $request) => 'Identificați pacientul',
            route('panou.waitlist.index') => 'Listă de așteptare',
            route('panou.series.index') => 'Serii de ședințe',
            route('panou.series.show', AppointmentSeries::first()) => 'Rămase',
            route('panou.series.create') => 'Regula seriei',
            route('panou.schedules.index') => 'Program de lucru',
            route('panou.schedules.show', $appointment->staff_member_id) => 'Program săptămânal',
            route('panou.patients.index') => 'Pacienți',
            route('panou.patients.show', $patient) => 'Date administrative',
            route('panou.patients.show', [$patient, 'tab' => 'programari']) => 'Programări',
            route('panou.patients.show', [$patient, 'tab' => 'servicii']) => 'Servicii efectuate',
            route('panou.patients.create') => 'Pacient nou',
        ] as $url => $text) {
            $this->get($url)->assertOk()->assertSee($text);
        }
        $this->getJson(route('panou.appointments.suggestions', ['service_id' => $appointment->service_id, 'location_id' => $location->id]))->assertOk();
    }

    public function test_clinical_dashboard_shows_my_day(): void
    {
        $this->seed(DemoSeeder::class);
        $medic = \App\Models\User::where('email', 'medic@demo.sevalia.invalid')->first();
        $this->actingAs($medic)->withSession(['auth.started_at' => time(), 'mfa.passed' => true]);
        $this->get(route('panou.dashboard'))->assertOk()->assertSee('Pacienții mei de azi');
    }

    public function test_settings_are_editable_with_permission_and_audited(): void
    {
        $this->actingAsRole(Roles::MANAGER);
        $this->get(route('panou.scheduling-settings.edit'))->assertOk()->assertSee('Ținerea intervalului');
        $payload = [];
        foreach (SchedulingSettings::definitions() as $key => $def) {
            $payload[str_replace('.', '_', $key)] = $def['type'] === 'checkbox' ? (int) $def['default'] : $def['default'];
        }
        $payload['scheduling_slot_minutes'] = 10;
        $payload['scheduling_online_hold_minutes'] = 30;
        $this->put(route('panou.scheduling-settings.update'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(10, app(SchedulingSettings::class)->get('scheduling.slot_minutes'));
        $this->assertSame(30, app(Settings::class)->get('scheduling.online.hold_minutes'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'scheduling.settings_updated']);

        $payload['scheduling_day_end'] = '06:00';
        $this->put(route('panou.scheduling-settings.update'), $payload)->assertSessionHasErrors('scheduling_day_end');
    }

    public function test_calendar_explains_missing_configuration(): void
    {
        \App\Modules\Clinic\Models\Location::create(['name' => 'Locație nouă', 'slug' => 'noua']);
        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.calendar'))->assertOk()->assertSee('nu are personal asociat')->assertSee('nu are încă săli sau aparate');
    }
}
