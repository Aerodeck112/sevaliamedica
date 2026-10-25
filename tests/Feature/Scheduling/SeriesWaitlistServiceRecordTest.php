<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Scheduling\Events\WaitlistSlotOffered;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\SeriesScheduler;
use App\Modules\Scheduling\Services\ServiceRecordService;
use App\Modules\Scheduling\Services\WaitlistService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SeriesWaitlistServiceRecordTest extends TestCase
{
    use SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
    }

    private function rule(int $count = 6): array
    {
        // Începem lunea viitoare, ca toate datele să fie în viitor.
        return ['start_date' => CarbonImmutable::today()->next('Monday')->toDateString(), 'weekdays' => [1, 3, 5], 'time' => '10:00', 'count' => $count];
    }

    public function test_series_plan_reports_conflicts_with_alternatives_and_partial_needs_consent(): void
    {
        $scheduler = app(SeriesScheduler::class);
        $patient = $this->patient();
        $rule = $this->rule();
        $dates = $scheduler->dates($rule);
        $this->assertCount(6, $dates);
        $this->assertSame([1, 3, 5, 1, 3, 5], array_map(fn ($d) => $d->isoWeekday(), $dates));

        // Ocupăm aparatul în ziua ședinței 2.
        app(BookingService::class)->book(new BookingRequest($this->patient()->id, $this->altergSession->id, $this->staff('Alt T', 'terapeut')->id, $this->location->id, $dates[1]), null);

        $plan = $scheduler->plan($patient->id, $this->altergSession->id, $this->therapist->id, $this->location->id, $rule);
        $this->assertSame([], $plan[0]['conflicts']);
        $this->assertNotEmpty($plan[1]['conflicts']);
        $this->assertNotEmpty($plan[1]['alternatives'], 'se propun alternative libere');

        $user = User::factory()->create();
        $choices = array_map(fn ($i) => ['index' => $i['index'], 'start' => $i['start']->format('Y-m-d H:i')], $plan);
        try {
            $scheduler->create($patient->id, $this->altergSession->id, $this->therapist->id, $this->location->id, $rule, $choices, $user, false);
            $this->fail('Fără acord, crearea parțială trebuie refuzată.');
        } catch (ValidationException) {
            $this->assertSame(0, Appointment::where('patient_id', $patient->id)->count(), 'nimic salvat');
            $this->assertSame(0, AppointmentSeries::count());
        }

        $choices[1]['start'] = $plan[1]['alternatives'][0]['start']->format('Y-m-d H:i');
        $result = $scheduler->create($patient->id, $this->altergSession->id, $this->therapist->id, $this->location->id, $rule, $choices, $user, false);
        $this->assertCount(6, $result['created']);
        $counts = $result['series']->counts();
        $this->assertSame(['planned' => 6, 'done' => 0, 'active' => 6, 'remaining' => 6], array_intersect_key($counts, array_flip(['planned', 'done', 'active', 'remaining'])));
    }

    public function test_series_future_sessions_can_be_rescheduled_together(): void
    {
        $scheduler = app(SeriesScheduler::class);
        $patient = $this->patient();
        $user = User::factory()->create();
        $plan = $scheduler->plan($patient->id, $this->gymSession->id, $this->therapist->id, $this->location->id, $this->rule(4));
        $series = $scheduler->create($patient->id, $this->gymSession->id, $this->therapist->id, $this->location->id, $this->rule(4),
            array_map(fn ($i) => ['index' => $i['index'], 'start' => $i['start']->format('Y-m-d H:i')], $plan), $user)['series'];

        $newRule = ['start_date' => $this->rule()['start_date'], 'weekdays' => [2, 4], 'time' => '15:00', 'count' => 1];
        $replan = $scheduler->planReschedule($series, $newRule);
        $this->assertCount(4, $replan);
        $result = $scheduler->applyReschedule($series, array_map(fn ($i) => ['appointment_id' => $i['appointment_id'], 'start' => $i['start']->format('Y-m-d H:i')], $replan), null, $user, 'Schimbare program pacient');
        $this->assertSame(4, $result['moved']);
        $this->assertSame([2, 4, 2, 4], $series->appointments()->get()->map(fn ($a) => $a->starts_at->isoWeekday())->all());
        $this->assertSame(['15:00'], $series->appointments()->get()->map(fn ($a) => $a->starts_at->format('H:i'))->unique()->values()->all());
    }

    public function test_cancellation_suggests_matching_waitlist_entries_and_offer_is_recorded(): void
    {
        Event::fake([WaitlistSlotOffered::class]);
        $booking = app(BookingService::class);
        $a = $booking->book(new BookingRequest($this->patient()->id, $this->consult->id, $this->medic->id, $this->location->id, $this->at('10:00', 3)), null);
        $match = WaitlistEntry::create(['patient_id' => $this->patient()->id, 'service_id' => $this->consult->id, 'priority' => 1, 'preferences' => ['from' => '08:00', 'to' => '12:00']]);
        WaitlistEntry::create(['patient_id' => $this->patient()->id, 'service_id' => $this->consult->id, 'priority' => 2, 'preferences' => ['from' => '14:00']]);
        WaitlistEntry::create(['patient_id' => $this->patient()->id, 'service_id' => $this->gymSession->id, 'priority' => 1]);

        $user = $this->actingAsRole(Roles::RECEPTIE);
        $booking->changeStatus($a, AppointmentStatus::ANULATA, $user, 'Pacientul a anunțat');
        $matches = app(WaitlistService::class)->matchesFor($a->fresh());
        $this->assertSame([$match->id], $matches->pluck('id')->all());

        $this->get(route('panou.appointments.show', $a))->assertOk()->assertSee('Lista de așteptare')->assertSee($match->patient->fullName());
        $this->post(route('panou.waitlist.offer', $match), [
            'freed_appointment_id' => $a->id, 'slot_start' => $a->starts_at->format('Y-m-d H:i'), 'slot_end' => $a->ends_at->format('Y-m-d H:i'),
        ])->assertRedirect();
        $this->assertSame('oferit', $match->fresh()->status);
        Event::assertDispatched(WaitlistSlotOffered::class);
    }

    public function test_service_record_correction_keeps_history(): void
    {
        $this->travelTo(CarbonImmutable::today()->setTime(8, 0));
        $booking = app(BookingService::class);
        $user = User::factory()->create();
        $a = $booking->book(new BookingRequest($this->patient()->id, $this->consult->id, $this->medic->id, $this->location->id, $this->at('09:00', 0)), null);
        $svc = app(ServiceRecordService::class);

        try {
            $svc->fromAppointment($a, [], $user);
            $this->fail('Înainte de sosire nu se poate înregistra serviciul.');
        } catch (ValidationException) {
        }
        $booking->changeStatus($a, AppointmentStatus::SOSIT, $user);
        $record = $svc->fromAppointment($a->fresh(), ['payer_type' => 'privat'], $user);
        $new = $svc->cancel($record, 'Sursa de plată greșită', $user, ['payer_type' => 'cas']);

        $this->assertSame('anulat', $record->fresh()->status);
        $this->assertSame($new->id, $record->fresh()->corrected_by_id);
        $this->assertSame($record->id, $new->corrects_id);
        $this->assertSame('cas', $new->payer_type);
        $this->assertSame(2, ServiceRecord::count(), 'nimic nu se șterge');
        $this->assertSame(1, ServiceRecord::done()->count());

        $this->expectException(ValidationException::class);
        $svc->manual($a->patient, ['service_id' => $this->consult->id, 'staff_member_id' => $this->medic->id, 'location_id' => $this->location->id, 'performed_at' => now(), 'payer_type' => 'privat', 'manual_reason' => ''], $user);
    }
}
