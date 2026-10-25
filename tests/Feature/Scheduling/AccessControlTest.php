<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\CareRelation;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Scenariul 4 (partea de programări/pacienți): acces interzis între roluri și fără relație de îngrijire. */
class AccessControlTest extends TestCase
{
    use SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
    }

    public function test_technical_admin_cannot_see_patients_or_calendar(): void
    {
        $p = $this->patient();
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->get(route('panou.patients.index'))->assertForbidden();
        $this->get(route('panou.patients.show', $p))->assertForbidden();
        $this->get(route('panou.calendar'))->assertForbidden();
        $this->getJson(route('panou.patients.search', ['q' => $p->last_name]))->assertForbidden();
    }

    public function test_roles_are_limited_to_their_actions(): void
    {
        $this->actingAsRole(Roles::FINANCIAR);
        $this->get(route('panou.appointments.create'))->assertForbidden();
        $this->get(route('panou.requests.index'))->assertForbidden();

        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.scheduling-settings.edit'))->assertForbidden();
        $this->get(route('panou.calendar'))->assertOk();

        $this->actingAsRole(Roles::TERAPEUT);
        $this->get(route('panou.waitlist.index'))->assertForbidden();
        $this->get(route('panou.schedules.index'))->assertForbidden();
    }

    public function test_clinical_staff_can_only_progress_their_own_appointments(): void
    {
        $this->travelTo(CarbonImmutable::today()->setTime(8, 0));
        $owner = User::factory()->withMfa()->create();
        $owner->assignRole(Roles::TERAPEUT);
        $this->therapist->update(['user_id' => $owner->id]);
        $other = $this->staff('Alt terapeut', 'terapeut');
        $booking = app(BookingService::class);
        $mine = $booking->book(new BookingRequest($this->patient()->id, $this->gymSession->id, $this->therapist->id, $this->location->id, $this->at('09:00', 0)), null);
        $theirs = $booking->book(new BookingRequest($this->patient()->id, $this->gymSession->id, $other->id, $this->location->id, $this->at('09:00', 0)), null);

        $this->actingAs($owner)->withSession(['auth.started_at' => time(), 'mfa.passed' => true]);
        $this->post(route('panou.appointments.status', $theirs), ['to' => AppointmentStatus::SOSIT])->assertForbidden();
        $this->post(route('panou.appointments.status', $mine), ['to' => AppointmentStatus::SOSIT])->assertRedirect();
        $this->post(route('panou.appointments.status', $mine), ['to' => AppointmentStatus::ANULATA, 'reason' => 'x'])->assertForbidden();
        $this->postJson(route('panou.appointments.move', $mine), ['date' => '2030-01-01', 'time' => '10:00', 'reason' => 'x'])->assertForbidden();
    }

    public function test_medical_access_requires_care_relationship(): void
    {
        $care = app(CareAccess::class);
        $doctorUser = User::factory()->withMfa()->create();
        $doctorUser->assignRole(Roles::MEDIC);
        $this->medic->update(['user_id' => $doctorUser->id]);
        $patient = $this->patient();
        $stranger = $this->patient();

        $this->assertFalse($care->canAccessMedical($doctorUser, $patient));

        app(BookingService::class)->book(new BookingRequest($patient->id, $this->consult->id, $this->medic->id, $this->location->id, $this->at('09:00')), null);
        $this->assertTrue(app(CareAccess::class)->canAccessMedical($doctorUser, $patient), 'programare în fereastra configurată');
        $this->assertFalse(app(CareAccess::class)->canAccessMedical($doctorUser, $stranger));

        CareRelation::create(['patient_id' => $stranger->id, 'staff_member_id' => $this->medic->id, 'source' => 'manual', 'reason' => 'Trimitere internă', 'valid_from' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDay()->toDateString()]);
        $this->assertFalse(app(CareAccess::class)->canAccessMedical($doctorUser, $stranger), 'relație expirată');

        // Receptionista nu are niciodată acces medical prin programări (nu e personal clinic legat).
        $receptionist = User::factory()->withMfa()->create();
        $receptionist->assignRole(Roles::RECEPTIE);
        $this->assertFalse(app(CareAccess::class)->canAccessMedical($receptionist, $patient));
    }

    public function test_medical_tabs_hidden_without_care_and_break_glass_is_audited(): void
    {
        app(PatientTabRegistry::class)->add(new PatientTab('fise-test', 'Fișe medicale (test)', 'patients.view', 30,
            fn ($p, $u) => view('patients::tabs.care', ['patient' => $p, 'relations' => collect(), 'user' => $u]), requiresCare: true));
        $doctor = $this->actingAsRole(Roles::MEDIC);
        $this->medic->update(['user_id' => $doctor->id]);
        $patient = $this->patient();

        $this->get(route('panou.patients.show', $patient))->assertOk()->assertDontSee('Fișe medicale (test)')->assertSee('date medicale ascunse');
        $this->post(route('panou.patients.break-glass.store', $patient), ['reason' => 'scurt'])->assertSessionHasErrors('reason');
        $this->post(route('panou.patients.break-glass.store', $patient), ['reason' => 'Pacient prezentat fără programare, medic curant absent'])->assertRedirect();
        $this->get(route('panou.patients.show', $patient))->assertSee('Fișe medicale (test)')->assertSee('acces de urgență');
        $this->assertDatabaseHas('audit_logs', ['action' => 'patients.break_glass', 'patient_id' => $patient->id]);

        $this->actingAsRole(Roles::RECEPTIE);
        $this->post(route('panou.patients.break-glass.store', $patient), ['reason' => 'Încercare fără permisiune de urgență'])->assertForbidden();
    }
}
