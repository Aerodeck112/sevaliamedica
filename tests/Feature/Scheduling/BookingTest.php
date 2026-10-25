<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Clinic\Models\Room;
use App\Modules\Identity\Roles;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentEvent;
use App\Modules\Scheduling\Models\ScheduleException;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use SchedulingFixtures;

    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
        $this->booking = app(BookingService::class);
    }

    private function req(int $serviceId, int $staffId, string $time, array $extra = []): BookingRequest
    {
        return new BookingRequest($this->patient()->id, $serviceId, $staffId, $this->location->id, $this->at($time), ...$extra);
    }

    private function conflictsOf(BookingRequest $r): array
    {
        try {
            $this->booking->book($r, null);
        } catch (BookingConflictException $e) {
            return $e->messages();
        }
        $this->fail('Se aștepta un conflict.');
    }

    public function test_books_staff_room_and_equipment_together(): void
    {
        $a = $this->booking->book($this->req($this->altergSession->id, $this->therapist->id, '09:00'), null);
        $types = $a->activeResources->pluck('resource_type')->sort()->values()->all();
        $this->assertSame(['equipment', 'staff'], $types);
        $this->assertSame('09:40', $a->blocked_until->format('H:i'), 'pauza de după se adaugă la intervalul blocat');
        $this->assertSame(1, AppointmentEvent::where('appointment_id', $a->id)->where('type', 'creata')->count());
    }

    public function test_staff_double_booking_is_refused(): void
    {
        $this->booking->book($this->req($this->consult->id, $this->medic->id, '09:00'), null);
        $msgs = $this->conflictsOf($this->req($this->consult->id, $this->medic->id, '09:15'));
        $this->assertStringContainsString('are deja o programare', implode(' ', $msgs));
    }

    public function test_equipment_is_exclusive_including_buffer(): void
    {
        $other = $this->staff('Alt terapeut', 'terapeut');
        $this->booking->book($this->req($this->altergSession->id, $this->therapist->id, '09:00'), null);
        // 09:30 cade în pauza de 10 minute de după (până la 09:40).
        $msgs = $this->conflictsOf($this->req($this->altergSession->id, $other->id, '09:30'));
        $this->assertStringContainsString('Niciun aparat de tip „alterg” liber', implode(' ', $msgs));
        $this->assertNotNull($this->booking->book($this->req($this->altergSession->id, $other->id, '09:40'), null));
    }

    public function test_room_capacity_is_respected(): void
    {
        $t2 = $this->staff('Terapeut 2', 'terapeut');
        $t3 = $this->staff('Terapeut 3', 'terapeut');
        $this->booking->book($this->req($this->gymSession->id, $this->therapist->id, '10:00'), null);
        $this->booking->book($this->req($this->gymSession->id, $t2->id, '10:15'), null);
        $msgs = $this->conflictsOf($this->req($this->gymSession->id, $t3->id, '10:30'));
        $this->assertStringContainsString('Nicio sală de tip „kineto” liberă', implode(' ', $msgs));
    }

    public function test_second_room_of_same_kind_is_allocated_automatically(): void
    {
        $cab2 = Room::create(['location_id' => $this->location->id, 'name' => 'Cabinet T2', 'kind' => 'cabinet', 'capacity' => 1]);
        $other = $this->staff('dr. Alt Medic', 'medic');
        $this->booking->book($this->req($this->consult->id, $this->medic->id, '09:00'), null);
        $b = $this->booking->book($this->req($this->consult->id, $other->id, '09:00'), null);
        $this->assertTrue($b->activeResources->contains(fn ($r) => $r->resource_type === 'room' && $r->resource_id === $cab2->id));
    }

    public function test_missing_resources_give_configuration_message(): void
    {
        $this->alterg->update(['is_active' => false]);
        $msgs = $this->conflictsOf($this->req($this->altergSession->id, $this->therapist->id, '09:00'));
        $this->assertStringContainsString('Configurați resursele în Administrare → Aparatură', implode(' ', $msgs));
    }

    public function test_outside_working_hours_requires_override_with_reason(): void
    {
        $msgs = $this->conflictsOf($this->req($this->consult->id, $this->medic->id, '19:00'));
        $this->assertStringContainsString('în afara programului de lucru', implode(' ', $msgs));
        $a = $this->booking->book($this->req($this->consult->id, $this->medic->id, '19:00', ['overrideSchedule' => true, 'overrideReason' => 'Pacient venit din alt oraș']), null);
        $this->assertSame('19:00', $a->starts_at->format('H:i'));
        // Pauza de prânz este și ea în afara programului.
        $this->conflictsOf($this->req($this->consult->id, $this->medic->id, '12:00'));
    }

    public function test_staff_without_any_schedule_gets_warning_not_conflict(): void
    {
        $free = \App\Modules\Clinic\Models\StaffMember::create(['display_name' => 'dr. Fără Program', 'first_name' => 'F', 'last_name' => 'P', 'kind' => 'medic']);
        $a = $this->booking->book($this->req($this->consult->id, $free->id, '21:00'), null);
        $this->assertStringContainsString('nu are încă program', $a->getRelation('bookingWarnings')->join(' '));
    }

    public function test_leave_and_closures_block_booking(): void
    {
        ScheduleException::create(['staff_member_id' => $this->medic->id, 'kind' => 'concediu', 'starts_at' => $this->at('00:00'), 'ends_at' => $this->at('23:59')]);
        $this->assertStringContainsString('indisponibil', implode(' ', $this->conflictsOf($this->req($this->consult->id, $this->medic->id, '09:00'))));

        ScheduleException::create(['staff_member_id' => null, 'location_id' => $this->location->id, 'kind' => 'inchidere', 'starts_at' => $this->at('00:00', 2), 'ends_at' => $this->at('23:59', 2), 'note' => 'Sărbătoare']);
        $r = new BookingRequest($this->patient()->id, $this->altergSession->id, $this->therapist->id, $this->location->id, $this->at('10:00', 2));
        $this->assertStringContainsString('închisă', implode(' ', $this->conflictsOf($r)));
    }

    public function test_cancellation_releases_resources(): void
    {
        $a = $this->booking->book($this->req($this->altergSession->id, $this->therapist->id, '09:00'), null);
        $this->booking->changeStatus($a, AppointmentStatus::ANULATA, null, 'Pacientul a anunțat');
        $this->assertSame(0, $a->activeResources()->count());
        $this->assertNotNull($this->booking->book($this->req($this->altergSession->id, $this->therapist->id, '09:00'), null));
    }

    public function test_status_transitions_are_validated_and_reasons_required(): void
    {
        $a = $this->booking->book($this->req($this->consult->id, $this->medic->id, '09:00'), null);

        try {
            $this->booking->changeStatus($a, AppointmentStatus::FINALIZATA, null);
            $this->fail('Confirmată → finalizată trebuie refuzată.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        try {
            $this->booking->changeStatus($a, AppointmentStatus::ANULATA, null, '');
            $this->fail('Anularea fără motiv trebuie refuzată.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        try {
            $this->booking->changeStatus($a, AppointmentStatus::NEPREZENTAT, null, 'nu a venit');
            $this->fail('Neprezentarea nu se poate marca înainte de oră.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('după ora programării', $e->errors()['status'][0]);
        }
        try {
            $this->booking->changeStatus($a, AppointmentStatus::SOSIT, null);
            $this->fail('Sosirea se marchează doar în ziua programării.');
        } catch (ValidationException) {
        }
        $this->assertSame(AppointmentStatus::CONFIRMATA, $a->fresh()->status, 'nicio schimbare automată');
    }

    public function test_move_checks_conflicts_and_version(): void
    {
        $a = $this->booking->book($this->req($this->consult->id, $this->medic->id, '09:00'), null);
        $this->booking->book($this->req($this->consult->id, $this->medic->id, '10:00'), null);
        $user = \App\Models\User::factory()->create();

        try {
            $this->booking->move($a, $this->at('10:15'), null, null, $a->version, $user, 'test');
            $this->fail('Mutarea peste altă programare trebuie refuzată.');
        } catch (BookingConflictException) {
        }
        $moved = $this->booking->move($a, $this->at('11:00'), null, null, $a->version, $user, 'La cererea pacientului');
        $this->assertSame('11:00', $moved->starts_at->format('H:i'));
        $this->assertSame(1, $moved->activeResources()->where('resource_type', 'staff')->count());
        $this->assertSame('La cererea pacientului', AppointmentEvent::where('appointment_id', $a->id)->where('type', 'mutata')->value('reason'));

        $this->expectException(BookingConflictException::class);
        $this->booking->move($a, $this->at('14:00'), null, null, 1, $user, 'versiune veche');
    }

    public function test_calendar_drag_endpoint_returns_conflict_json(): void
    {
        $a = $this->booking->book($this->req($this->consult->id, $this->medic->id, '09:00'), null);
        $this->booking->book($this->req($this->consult->id, $this->medic->id, '10:00'), null);
        $this->actingAsRole(Roles::RECEPTIE);

        $this->postJson(route('panou.appointments.move', $a), ['date' => $this->at('10:00')->toDateString(), 'time' => '10:00', 'version' => $a->version, 'reason' => 'test'])
            ->assertStatus(409)->assertJsonPath('ok', false);
        $this->postJson(route('panou.appointments.move', $a), ['date' => $this->at('14:00')->toDateString(), 'time' => '14:00', 'version' => $a->version, 'reason' => 'Corectarea programării'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('14:00', Appointment::find($a->id)->starts_at->format('H:i'));
    }
}
