<?php

namespace Tests\Feature\Rehab;

use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Rehab\Models\PackageDefinition;
use App\Modules\Rehab\Models\PatientPackage;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Models\RehabSessionRecord;
use App\Modules\Rehab\Services\SessionProgress;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Feature\Medical\MedicalFixtures;
use Tests\TestCase;

/** Scenariul 2: serie de recuperare cu terapeut, sală și aparat; efectuare explicită; evidența ședințelor. */
class RehabFlowTest extends TestCase
{
    use MedicalFixtures;

    private User $receptie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMedical();
        $this->receptie = User::factory()->withMfa()->create();
        $this->receptie->assignRole(Roles::RECEPTIE);
        // AlterG cere și o sală de kinetoterapie: terapeut + sală + aparat rezervate simultan.
        $this->altergSession->requirements()->create(['resource_type' => 'room', 'resource_kind' => 'kineto', 'quantity' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function jumpTo(CarbonImmutable $to): void
    {
        Carbon::setTestNow($to);
        CarbonImmutable::setTestNow($to);
    }

    private function approvedPlan(int $sessions = 4, string $payer = 'privat', ?int $packageId = null): RehabPlan
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $this->as($this->medicUser)->post(route('panou.rehab.plans.store', $patient), [
            'location_id' => $this->location->id, 'payer_type' => $payer, 'patient_package_id' => $packageId,
            'objectives' => 'Obiectiv clinic fictiv OBIECTIV-SECRET',
        ])->assertSessionHasNoErrors();
        $plan = RehabPlan::latest('id')->firstOrFail();
        $this->as($this->medicUser)->post(route('panou.rehab.plans.items.store', $plan), [
            'service_id' => $this->altergSession->id, 'sessions_count' => $sessions, 'sessions_per_week' => 2, 'preferred_therapist_id' => $this->therapist->id,
        ])->assertSessionHasNoErrors();
        $this->as($this->medicUser)->post(route('panou.rehab.plans.approve', $plan))->assertSessionHasNoErrors();

        return $plan->fresh(['items', 'patient']);
    }

    private function rule(int $count): array
    {
        return ['start_date' => CarbonImmutable::today()->next('Monday')->toDateString(), 'weekdays' => [1, 3], 'time' => '10:00', 'count' => $count, 'staff_member_id' => $this->therapist->id];
    }

    public function test_full_rehab_series_with_therapist_room_and_equipment(): void
    {
        $plan = $this->approvedPlan();
        $item = $plan->items->first();
        $this->assertSame(RehabPlan::APPROVED, $plan->status);

        // Aparatul este ocupat la ora celei de-a doua ședințe.
        $second = CarbonImmutable::today()->next('Monday')->addDays(2)->setTime(10, 0);
        app(BookingService::class)->book(new BookingRequest($this->patient()->id, $this->altergSession->id, $this->staff('Alt terapeut', 'terapeut')->id, $this->location->id, $second), null);

        // Previzualizarea arată conflictul, cu alternative.
        $preview = $this->as($this->receptie)->post(route('panou.rehab.plans.items.schedule-preview', $item), $this->rule(4))->assertOk();
        $preview->assertSee('conflict')->assertSee('AlterG T');

        // Fără acord, crearea parțială e refuzată și nu se salvează nimic.
        $choices = [];
        foreach (range(1, 4) as $i) {
            $choices[$i] = ['include' => 1, 'start' => CarbonImmutable::today()->next('Monday')->addDays([0, 2, 7, 9][$i - 1])->setTime(10, 0)->format('Y-m-d H:i')];
        }
        $this->as($this->receptie)->post(route('panou.rehab.plans.items.schedule-store', $item), $this->rule(4) + ['choice' => $choices])->assertSessionHasErrors('series');
        $this->assertSame(0, Appointment::where('patient_id', $plan->patient_id)->count());

        // Cu alternativă pentru ședința 2: toate se rezervă.
        $choices[2]['start'] = $second->setTime(14, 0)->format('Y-m-d H:i');
        $this->as($this->receptie)->post(route('panou.rehab.plans.items.schedule-store', $item), $this->rule(4) + ['choice' => $choices])->assertSessionHasNoErrors();
        $appointments = Appointment::where('patient_id', $plan->patient_id)->with('activeResources')->orderBy('starts_at')->get();
        $this->assertCount(4, $appointments);
        $this->assertSame(RehabPlan::ACTIVE, $plan->fresh()->status);
        foreach ($appointments as $a) {
            $types = $a->activeResources->pluck('resource_type')->sort()->values()->all();
            $this->assertSame(['equipment', 'room', 'staff'], $types, 'terapeut, sală și aparat rezervate împreună');
            $this->assertSame('privat', $a->payer_type);
        }

        $progress = app(SessionProgress::class);
        $p = $progress->forItem($item->fresh());
        $this->assertSame([4, 0, 4, 0, 4, 0], [$p['prescribed'], $p['done'], $p['scheduled'], $p['pending'], $p['remaining'], $p['unscheduled']]);

        // Ora trece: nimic nu devine „efectuat” automat.
        $first = $appointments[0];
        $this->jumpTo($first->starts_at->addHours(3));
        $p = $progress->forItem($item->fresh());
        $this->assertSame(0, $p['done']);
        $this->assertSame(1, $p['pending']);
        $this->assertSame(AppointmentStatus::CONFIRMATA, $first->fresh()->status);
        $this->assertSame(0, ServiceRecord::count());

        // Terapeutul marchează explicit ședința (în aceeași zi).
        $this->as($this->therapistUser)->get(route('panou.rehab.sessions.create', $first))->assertOk();
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $first), ['confirm' => 1, 'observations' => 'Observație fictivă'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::FINALIZATA, $first->fresh()->status);
        $record = ServiceRecord::firstOrFail();
        $this->assertSame([$first->id, $this->altergSession->id, 'privat', 'efectuat'], [$record->appointment_id, $record->service_id, $record->payer_type, $record->status]);
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $first), ['confirm' => 1])->assertSessionHasErrors('status');
        $this->assertSame(1, ServiceRecord::count(), 'nicio dublare');

        // Recepția anulează ședința 3 → rămâne o ședință neprogramată.
        app(BookingService::class)->changeStatus($appointments[2], AppointmentStatus::ANULATA, $this->receptie, 'Pacientul a anunțat (test)');
        $p = $progress->forItem($item->fresh());
        $this->assertSame([1, 2, 0, 1, 3, 1], [$p['done'], $p['scheduled'], $p['pending'], $p['cancelled'], $p['remaining'], $p['unscheduled']]);

        // Ședința 2, nemarcată în ziua ei: confirmare ulterioară doar cu motiv.
        $this->jumpTo($appointments[1]->starts_at->addDay()->setTime(9, 0));
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $appointments[1]), ['confirm' => 1])->assertSessionHasErrors('late_reason');
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $appointments[1]), ['confirm' => 1, 'late_reason' => 'Uitasem să marchez (test)'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointments[1]->id, 'to_status' => AppointmentStatus::FINALIZATA, 'reason' => 'Prezență confirmată ulterior: Uitasem să marchez (test)']);
        $p = $progress->forItem($item->fresh());
        $this->assertSame([2, 2], [$p['done'], $p['remaining']]);

        // O corectare a serviciului efectuat scoate ședința din „efectuate”.
        app(\App\Modules\Scheduling\Services\ServiceRecordService::class)->cancel(ServiceRecord::where('appointment_id', $appointments[1]->id)->first(), 'Înregistrare greșită (test)', $this->receptie);
        $this->assertSame(1, $progress->forItem($item->fresh())['done']);

        // Recepția vede logistica, nu și conținutul clinic.
        $this->as($this->receptie)->get(route('panou.rehab.plans.show', $plan))->assertOk()->assertSee('AlterG')->assertDontSee('OBIECTIV-SECRET');
        $this->as($this->medicUser)->get(route('panou.rehab.plans.show', $plan))->assertOk()->assertSee('OBIECTIV-SECRET');
        $this->assertDatabaseHas('audit_logs', ['action' => 'rehab.session_performed', 'patient_id' => $plan->patient_id]);
    }

    public function test_session_access_and_plan_rules(): void
    {
        $plan = $this->approvedPlan(2);
        $item = $plan->items->first();
        $start = CarbonImmutable::today()->next('Monday')->setTime(10, 0);
        $choices = [1 => ['include' => 1, 'start' => $start->format('Y-m-d H:i')], 2 => ['include' => 1, 'start' => $start->addDays(2)->format('Y-m-d H:i')]];
        $this->as($this->receptie)->post(route('panou.rehab.plans.items.schedule-store', $item), $this->rule(2) + ['choice' => $choices])->assertSessionHasNoErrors();
        $a = Appointment::where('patient_id', $plan->patient_id)->orderBy('starts_at')->first();

        // Înainte de ora ședinței nu se poate marca.
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $a), ['confirm' => 1])->assertSessionHasErrors('status');

        $this->jumpTo($a->starts_at->addMinutes(5));
        // Un alt terapeut (fără programarea respectivă) nu poate marca ședința.
        $other = User::factory()->withMfa()->create();
        $other->assignRole(Roles::TERAPEUT);
        $this->staff('Alt terapeut 2', 'terapeut', $other);
        $this->as($other)->post(route('panou.rehab.sessions.store', $a), ['confirm' => 1])->assertForbidden();
        // Recepția nu are dreptul de a marca ședințe.
        $this->as($this->receptie)->post(route('panou.rehab.sessions.store', $a), ['confirm' => 1])->assertForbidden();

        // Planul suspendat nu permite marcarea; nici programarea.
        $this->as($this->medicUser)->post(route('panou.rehab.plans.suspend', $plan), ['reason' => 'Pauză (test)'])->assertSessionHasNoErrors();
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $a), ['confirm' => 1])->assertSessionHasErrors('status');
        $this->as($this->medicUser)->post(route('panou.rehab.plans.resume', $plan))->assertSessionHasNoErrors();
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $a), ['confirm' => 1])->assertSessionHasNoErrors();

        // Încheierea cere rezolvarea ședințelor trecute și acordul pentru anularea celor viitoare.
        $this->as($this->medicUser)->post(route('panou.rehab.plans.close', $plan), ['conclusion' => 'Concluzie fictivă'])->assertSessionHasErrors('cancel_future');
        $this->as($this->medicUser)->post(route('panou.rehab.plans.close', $plan), ['conclusion' => 'Concluzie fictivă', 'cancel_future' => 1])->assertSessionHasNoErrors();
        $this->assertSame(RehabPlan::CLOSED, $plan->fresh()->status);
        $this->assertSame(AppointmentStatus::ANULATA, Appointment::where('patient_id', $plan->patient_id)->orderBy('starts_at')->get()[1]->status);

        // Un plan aprobat nu se mai modifică (doar numărul de ședințe, cu motiv).
        $this->as($this->medicUser)->post(route('panou.rehab.plans.items.store', $plan), ['service_id' => $this->gymSession->id, 'sessions_count' => 3])->assertSessionHasErrors('status');
    }

    public function test_package_consumption_is_limited(): void
    {
        $patient = $this->patient();
        $definition = PackageDefinition::create(['name' => 'Pachet fictiv', 'sessions_count' => 1, 'validity_days' => 60, 'service_ids' => [$this->altergSession->id]]);
        $package = PatientPackage::create(['patient_id' => $patient->id, 'rehab_package_definition_id' => $definition->id, 'sessions_total' => 1, 'valid_from' => now()->toDateString(), 'valid_until' => now()->addDays(59)->toDateString()]);

        $this->careVia($patient, $this->medic);
        $this->as($this->medicUser)->post(route('panou.rehab.plans.store', $patient), ['location_id' => $this->location->id, 'payer_type' => 'pachet', 'patient_package_id' => $package->id])->assertSessionHasNoErrors();
        $plan = RehabPlan::latest('id')->firstOrFail();
        $this->as($this->medicUser)->post(route('panou.rehab.plans.items.store', $plan), ['service_id' => $this->altergSession->id, 'sessions_count' => 2, 'preferred_therapist_id' => $this->therapist->id]);
        $this->as($this->medicUser)->post(route('panou.rehab.plans.approve', $plan));
        $item = $plan->fresh('items')->items->first();

        $start = CarbonImmutable::today()->next('Monday')->setTime(10, 0);
        $choices = [1 => ['include' => 1, 'start' => $start->format('Y-m-d H:i')], 2 => ['include' => 1, 'start' => $start->setTime(15, 0)->format('Y-m-d H:i')]];
        $this->as($this->receptie)->post(route('panou.rehab.plans.items.schedule-store', $item),
            ['start_date' => $start->toDateString(), 'weekdays' => [1], 'time' => '10:00', 'count' => 2, 'staff_member_id' => $this->therapist->id, 'choice' => $choices])->assertSessionHasNoErrors();
        [$a1, $a2] = Appointment::where('patient_id', $patient->id)->orderBy('starts_at')->get()->all();

        $this->jumpTo($a2->starts_at->addMinutes(5));
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $a1), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $package->fresh()->used());
        $this->as($this->therapistUser)->post(route('panou.rehab.sessions.store', $a2), ['confirm' => 1])->assertSessionHasErrors('payer');
        $this->assertSame(1, RehabSessionRecord::count());
        $this->assertSame('pachet', ServiceRecord::first()->payer_type);
    }
}
