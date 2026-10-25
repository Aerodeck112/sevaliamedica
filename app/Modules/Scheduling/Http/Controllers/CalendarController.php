<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\SchedulingSettings;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CalendarController extends Controller
{
    /** Înălțimea unui minut în grilă (2 px → o oră = 120 px; o programare de 15 min rămâne lizibilă). */
    private const PX_PER_MIN = 2;

    public function index(Request $request, AvailabilityService $availability, SchedulingSettings $settings): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'view' => ['nullable', 'in:zi,saptamana'],
            'group' => ['nullable', 'in:personal,sali'],
            'location_id' => ['nullable', 'integer'],
            'specialty_id' => ['nullable', 'integer'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['integer'],
            'room_id' => ['nullable', 'integer'],
            'equipment_id' => ['nullable', 'integer'],
            'cancelled' => ['nullable', 'boolean'],
        ]);

        $date = CarbonImmutable::parse($filters['date'] ?? 'today')->startOfDay();
        $view = $filters['view'] ?? 'zi';
        $group = $filters['group'] ?? 'personal';
        $locations = Location::active()->get();
        $location = $locations->firstWhere('id', (int) ($filters['location_id'] ?? 0)) ?? $locations->first();

        $dayStart = (string) $settings->get('scheduling.day_start');
        $dayEnd = (string) $settings->get('scheduling.day_end');
        $slot = (int) $settings->get('scheduling.slot_minutes');
        [$sh, $sm] = array_map('intval', explode(':', $dayStart));
        [$eh, $em] = array_map('intval', explode(':', $dayEnd));
        $startMin = $sh * 60 + $sm;
        $endMin = max($startMin + 60, $eh * 60 + $em);

        $staffQuery = StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])
            ->when($location, fn ($q) => $q->whereHas('locations', fn ($l) => $l->where('locations.id', $location->id)))
            ->when($filters['specialty_id'] ?? null, fn ($q, $s) => $q->whereHas('specialties', fn ($w) => $w->where('specialties.id', $s)));
        $allStaff = $staffQuery->get();
        $selectedStaff = array_map('intval', $filters['staff'] ?? []);
        $staffShown = $selectedStaff ? $allStaff->whereIn('id', $selectedStaff)->values() : $allStaff;
        $rooms = $location ? Room::where('location_id', $location->id)->where('is_active', true)->orderBy('name')->get() : collect();
        $equipment = $location ? Equipment::where('location_id', $location->id)->where('is_active', true)->orderBy('name')->get() : collect();

        $rangeStart = $view === 'saptamana' ? $date->startOfWeek() : $date;
        $rangeEnd = $view === 'saptamana' ? $rangeStart->addDays(7) : $date->addDay();

        $appointments = Appointment::visibleInCalendar()
            ->with(['patient', 'service', 'staffMember', 'activeResources'])
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
            ->where('starts_at', '<', $rangeEnd)->where('ends_at', '>', $rangeStart)
            ->when(! ($filters['cancelled'] ?? false), fn ($q) => $q->where('status', '!=', AppointmentStatus::ANULATA))
            ->when($filters['equipment_id'] ?? null, fn ($q, $e) => $q->whereHas('activeResources', fn ($r) => $r->where('resource_type', 'equipment')->where('resource_id', $e)))
            ->when($filters['room_id'] ?? null, fn ($q, $r) => $q->whereHas('resources', fn ($w) => $w->where('resource_type', 'room')->where('resource_id', $r)))
            ->when($selectedStaff, fn ($q) => $q->whereIn('staff_member_id', $selectedStaff))
            ->orderBy('starts_at')->get();

        $columns = [];
        if ($view === 'zi' && $group === 'personal') {
            $hidden = 0;
            foreach ($staffShown as $s) {
                $hasSchedule = $availability->hasAnySchedule($s->id);
                $working = $location && $hasSchedule ? $availability->workingIntervals($s->id, $location->id, $date) : [];
                $own = $appointments->where('staff_member_id', $s->id);
                // Fără filtru pe persoană, afișăm doar cine lucrează în ziua respectivă sau are programări;
                // ceilalți se pot alege din filtrul „Persoană”.
                if (! $selectedStaff && ! $working && $own->isEmpty()) {
                    $hidden++;

                    continue;
                }
                $windows = $hasSchedule ? $working : ($location ? $availability->openingIntervals($location, $date) : []);
                $columns[] = $this->column('staff', $s->id, $s->display_name, $s->calendar_color, $date, $windows, $own, $startMin, ! $hasSchedule);
            }
        } elseif ($view === 'zi') {
            foreach ($rooms->when($filters['room_id'] ?? null, fn ($c, $r) => $c->where('id', $r)) as $room) {
                $columns[] = $this->column('room', $room->id, $room->name.($room->capacity > 1 ? ' (cap. '.$room->capacity.')' : ''), '#007878', $date,
                    $availability->openingIntervals($location, $date),
                    $appointments->filter(fn ($a) => $a->activeResources->contains(fn ($r) => $r->resource_type === 'room' && $r->resource_id === $room->id)), $startMin, false);
            }
        } else {
            for ($i = 0; $i < 7; $i++) {
                $d = $rangeStart->addDays($i);
                $windows = $staffShown->count() === 1 && $location
                    ? ($availability->hasAnySchedule($staffShown->first()->id) ? $availability->workingIntervals($staffShown->first()->id, $location->id, $d) : $availability->openingIntervals($location, $d))
                    : ($location ? $availability->openingIntervals($location, $d) : []);
                $columns[] = $this->column('date', $d->format('Ymd'), $d->locale('ro')->isoFormat('ddd D MMM'), null, $d, $windows,
                    $appointments->filter(fn ($a) => $a->starts_at->isSameDay($d)), $startMin, false, $d->isToday());
            }
        }

        return view('scheduling::calendar', [
            'date' => $date, 'view' => $view, 'group' => $group, 'filters' => $filters,
            'locations' => $locations, 'location' => $location,
            'specialties' => Specialty::active()->get(), 'allStaff' => $allStaff, 'selectedStaff' => $selectedStaff,
            'rooms' => $rooms, 'equipment' => $equipment,
            'columns' => $columns, 'startMin' => $startMin, 'endMin' => $endMin, 'slot' => $slot, 'px' => self::PX_PER_MIN,
            'rangeStart' => $rangeStart, 'rangeEnd' => $rangeEnd,
            'nowMin' => now()->hour * 60 + now()->minute,
            'counts' => $appointments->countBy('status'),
            'noResources' => $location && $rooms->isEmpty() && $equipment->isEmpty(),
            'noStaff' => $allStaff->isEmpty(),
            'hiddenStaff' => $hidden ?? 0,
        ]);
    }

    /**
     * Coloana calendarului cu intervalele disponibile și cardurile poziționate pe benzi (lanes) fără suprapunere vizuală.
     */
    private function column(string $type, int|string $id, string $label, ?string $color, CarbonImmutable $date, array $windows, Collection $appointments, int $startMin, bool $noSchedule, bool $isToday = false): array
    {
        $px = self::PX_PER_MIN;
        $cards = [];
        $laneEnds = [];
        $sorted = $appointments->sortBy('starts_at')->values();
        foreach ($sorted as $a) {
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $a->starts_at) {
                $lane++;
            }
            $laneEnds[$lane] = $a->ends_at;
            $top = max(0, ($a->starts_at->hour * 60 + $a->starts_at->minute - $startMin) * $px);
            $cards[] = ['a' => $a, 'lane' => $lane, 'top' => $top, 'height' => max(18, $a->durationMinutes() * $px)];
        }
        $lanes = max(1, count($laneEnds));

        return [
            'type' => $type, 'id' => $id, 'label' => $label, 'color' => $color, 'date' => $date,
            'lanes' => $lanes, 'cards' => $cards, 'noSchedule' => $noSchedule, 'isToday' => $isToday || ($type !== 'date' && $date->isToday()),
            'windows' => array_map(fn ($w) => [
                'top' => max(0, ($w[0]->hour * 60 + $w[0]->minute - $startMin) * $px),
                'height' => max(0, $w[0]->diffInMinutes($w[1]) * $px),
            ], $windows),
        ];
    }
}
