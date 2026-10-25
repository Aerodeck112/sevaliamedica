<?php

namespace Tests\Feature\Scheduling;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Core\Settings\Settings;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Events\ServiceRecordChanged;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Scenariul 1: formular public → solicitare în CRM → confirmare → prezentare → serviciu efectuat. */
class PublicRequestFlowTest extends TestCase
{
    use SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
    }

    private function submit(?CarbonImmutable $preferred = null, ?int $staffId = null): \App\Core\Contracts\IntakeResult
    {
        return app(AppointmentIntake::class)->submit(new PublicAppointmentRequest(
            'Exemplu Fictiv Ion', '0700 123 456', 'ion@demo.sevalia.invalid', 'telefon', null, $staffId,
            $preferred ? $this->consult->id : null, $this->location->id, $preferred, 'Mesaj de test', null,
            CarbonImmutable::now(), 'https://exemplu.test/contactus', hash('sha256', '127.0.0.1'),
        ));
    }

    public function test_full_flow_from_public_form_to_performed_service(): void
    {
        Event::fake([ServiceRecordChanged::class]);
        // Ora fixă (08:00 azi), ca scenariul să nu depindă de momentul rulării.
        $this->travelTo(CarbonImmutable::today()->setTime(8, 0));

        // 1. Formularul public: se înregistrează solicitarea, fără promisiunea unui interval.
        $result = $this->submit();
        $this->assertTrue($result->accepted);
        $this->assertFalse($result->confirmed);
        $this->assertStringContainsString('nu reprezintă încă o programare confirmată', $result->publicMessage);
        $request = Appointment::where('request_reference', $result->reference)->firstOrFail();
        $this->assertSame(AppointmentStatus::SOLICITARE, $request->status);
        $this->assertNull($request->patient_id, 'formularul nu creează fișe de pacient');
        $this->assertSame('0700 123 456', $request->request_contact['phone']);
        $this->assertStringNotContainsString('0700 123 456', (string) \DB::table('appointments')->where('id', $request->id)->value('request_contact'), 'contactul e criptat');

        // 2. Recepția vede solicitarea, creează fișa și o asociază.
        $receptionist = $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.requests.index'))->assertOk()->assertSee('Exemplu Fictiv Ion');
        $this->get(route('panou.requests.show', $request))->assertOk()->assertSee('Identificați pacientul');
        $this->post(route('panou.patients.store'), ['last_name' => 'Exemplu Fictiv', 'first_name' => 'Ion', 'phone' => '0700 123 456', 'allow_phone' => 1])->assertRedirect();
        $patient = Patient::where('search_name', 'exemplu fictiv ion')->firstOrFail();
        $this->post(route('panou.requests.attach', $request), ['patient_id' => $patient->id])->assertRedirect();

        // 3. Confirmarea rezervă medicul și cabinetul.
        $slot = $this->at('09:00', 0);
        $this->medic->update(['user_id' => ($medicUser = User::factory()->withMfa()->create())->id]);
        $medicUser->assignRole(Roles::MEDIC);
        $this->post(route('panou.requests.confirm', $request), [
            'service_id' => $this->consult->id, 'staff_member_id' => $this->medic->id, 'location_id' => $this->location->id,
            'date' => $slot->toDateString(), 'time' => $slot->format('H:i'),
        ])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(AppointmentStatus::CONFIRMATA, $request->status);
        $this->assertSame(2, $request->activeResources()->count(), 'medic + cabinet');

        // 4. Ziua programării: sosit (recepția) → în consultație → finalizată (medicul, pentru propria programare).
        $this->post(route('panou.appointments.status', $request), ['to' => AppointmentStatus::SOSIT])->assertRedirect();
        $this->actingAs($medicUser)->withSession(['auth.started_at' => time(), 'mfa.passed' => true]);
        $this->post(route('panou.appointments.status', $request), ['to' => AppointmentStatus::IN_DESFASURARE])->assertRedirect();
        $this->post(route('panou.appointments.status', $request), ['to' => AppointmentStatus::FINALIZATA])->assertRedirect();
        $this->assertSame(AppointmentStatus::FINALIZATA, $request->fresh()->status);
        $this->assertSame(0, ServiceRecord::count(), 'finalizarea nu creează automat serviciul efectuat');

        // 5. Serviciul efectuat, înregistrat explicit.
        $this->post(route('panou.service-records.from-appointment', $request), [
            'service_id' => $this->consult->id, 'staff_member_id' => $this->medic->id, 'payer_type' => 'cas', 'quantity' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $record = ServiceRecord::firstOrFail();
        $this->assertSame([$patient->id, 'cas', 'efectuat'], [$record->patient_id, $record->payer_type, $record->status]);
        Event::assertDispatched(ServiceRecordChanged::class, fn ($e) => $e->action === 'recorded');

        // Un al doilea clic nu dublează serviciul.
        $this->post(route('panou.service-records.from-appointment', $request), [
            'service_id' => $this->consult->id, 'staff_member_id' => $this->medic->id, 'payer_type' => 'cas', 'quantity' => 1,
        ])->assertSessionHasErrors('service_id');
        $this->assertSame(1, ServiceRecord::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'scheduling.service_recorded', 'patient_id' => $patient->id]);
    }

    public function test_auto_confirmation_only_when_all_rules_allow(): void
    {
        $slot = $this->at('10:00', 3);

        // Implicit, confirmarea automată e dezactivată din setări.
        $this->consult->update(['online_auto_confirm' => true]);
        $r = $this->submit($slot, $this->medic->id);
        $this->assertFalse($r->confirmed);

        app(Settings::class)->set('scheduling.online.auto_confirm', true);
        $r = $this->submit($slot, $this->medic->id);
        $this->assertTrue($r->confirmed);
        $this->assertStringContainsString('confirmată pentru', $r->publicMessage);
        $a = Appointment::where('request_reference', $r->reference)->first();
        $this->assertSame(AppointmentStatus::CONFIRMATA, $a->status);
        $this->assertNull($a->patient_id);

        // Același interval, a doua cerere: nu se confirmă, rămâne solicitare.
        $r2 = $this->submit($slot, $this->medic->id);
        $this->assertFalse($r2->confirmed);

        // Avansul minim (24 h implicit) previne confirmarea automată pentru intervale prea apropiate.
        $r3 = $this->submit(CarbonImmutable::now()->addHours(2), $this->medic->id);
        $this->assertFalse($r3->confirmed);
    }

    public function test_online_requests_can_be_disabled(): void
    {
        app(Settings::class)->set('scheduling.online.enabled', false);
        $r = $this->submit();
        $this->assertFalse($r->accepted);
        $this->assertSame(0, Appointment::count());
    }
}
