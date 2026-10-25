<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Models\ScheduleException;
use App\Modules\Scheduling\Models\StaffSchedule;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Programul de lucru efectiv: șabloane săptămânale − pauze − concedii/liber − închideri + ore suplimentare.
 */
class AvailabilityService
{
    public function __construct(private readonly SchedulingSettings $settings) {}

    public function hasAnySchedule(int $staffId): bool
    {
        return StaffSchedule::where('staff_member_id', $staffId)->exists();
    }

    /**
     * Intervalele de lucru ale persoanei într-o zi, într-o locație.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function workingIntervals(int $staffId, int $locationId, CarbonImmutable $date): array
    {
        $day = $date->startOfDay();
        $intervals = [];

        $rows = StaffSchedule::where('staff_member_id', $staffId)->where('location_id', $locationId)
            ->where('weekday', $day->isoWeekday())
            ->where('valid_from', '<=', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $day->toDateString()))
            ->get();
        foreach ($rows as $row) {
            $start = $day->setTimeFromTimeString($row->start_time);
            $end = $day->setTimeFromTimeString($row->end_time);
            $pieces = [[$start, $end]];
            foreach ((array) $row->breaks as $break) {
                if (empty($break[0]) || empty($break[1])) {
                    continue;
                }
                $pieces = self::subtract($pieces, $day->setTimeFromTimeString($break[0]), $day->setTimeFromTimeString($break[1]));
            }
            array_push($intervals, ...$pieces);
        }

        $exceptions = $this->exceptions($staffId, $locationId, $day, $day->endOfDay());
        foreach ($exceptions->where('kind', 'ore_suplimentare') as $extra) {
            if ($extra->staff_member_id === $staffId) {
                $intervals[] = [max($extra->starts_at, $day), min($extra->ends_at, $day->endOfDay())];
            }
        }
        foreach ($exceptions->filter->isUnavailability() as $off) {
            $intervals = self::subtract($intervals, $off->starts_at, $off->ends_at);
        }

        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);

        return self::mergeIntervals($intervals);
    }

    /** Excepțiile care afectează persoana sau locația în intervalul dat. @return Collection<int, ScheduleException> */
    public function exceptions(?int $staffId, int $locationId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ScheduleException::where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->where(function ($q) use ($staffId, $locationId) {
                $q->whereNull('staff_member_id')->where(fn ($l) => $l->whereNull('location_id')->orWhere('location_id', $locationId));
                if ($staffId) {
                    $q->orWhere('staff_member_id', $staffId);
                }
            })->get();
    }

    /** Închiderea locației/clinicii care se suprapune cu intervalul, dacă există. */
    public function closure(int $locationId, CarbonImmutable $from, CarbonImmutable $to): ?ScheduleException
    {
        return ScheduleException::where('kind', 'inchidere')->whereNull('staff_member_id')
            ->where(fn ($l) => $l->whereNull('location_id')->orWhere('location_id', $locationId))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->first();
    }

    public function isWithinWorkingHours(int $staffId, int $locationId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($this->workingIntervals($staffId, $locationId, $start) as [$a, $b]) {
            if ($a <= $start && $b >= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Intervale libere pentru un serviciu, începând de la $from. Folosește verificarea completă (fără blocări).
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, staff_member_id: ?int, staff: ?string}>
     */
    public function suggest(BookingService $booking, BookingRequest $template, ?int $excludeAppointmentId = null, ?int $limit = null, int $days = 21): array
    {
        $limit ??= (int) $this->settings->get('scheduling.suggestions');
        $service = Service::findOrFail($template->serviceId);
        $step = max(5, (int) $this->settings->get('scheduling.slot_minutes'));
        $duration = $template->endsAt ? (int) $template->startsAt->diffInMinutes($template->endsAt) : $service->duration_minutes;
        $staffIds = $template->staffMemberId ? [$template->staffMemberId] : $this->eligibleStaff($service, $template->locationId)->pluck('id')->all();
        $location = Location::find($template->locationId);
        $now = CarbonImmutable::now();
        $from = $template->startsAt->max($now->addMinutes($step - ($now->minute % $step))->startOfMinute());

        $out = [];
        for ($d = 0; $d < $days && count($out) < $limit; $d++) {
            $day = $from->startOfDay()->addDays($d);
            foreach ($staffIds as $staffId) {
                $windows = $this->hasAnySchedule($staffId)
                    ? $this->workingIntervals($staffId, $template->locationId, $day)
                    : $this->openingIntervals($location, $day);
                foreach ($windows as [$a, $b]) {
                    for ($t = $a; $t->addMinutes($duration) <= $b && count($out) < $limit; $t = $t->addMinutes($step)) {
                        if ($t < $from) {
                            continue;
                        }
                        $candidate = $template->with(['startsAt' => $t, 'endsAt' => $t->addMinutes($duration), 'staffMemberId' => $staffId, 'roomIds' => [], 'equipmentIds' => []]);
                        if ($booking->evaluate($candidate, $excludeAppointmentId)['conflicts'] === []) {
                            $out[] = ['start' => $t, 'end' => $t->addMinutes($duration), 'staff_member_id' => $staffId, 'staff' => StaffMember::find($staffId)?->display_name];
                        }
                    }
                }
            }
            usort($out, fn ($x, $y) => $x['start'] <=> $y['start']);
        }

        return array_slice($out, 0, $limit);
    }

    /** Personalul activ din locație potrivit pentru serviciu (tip personal și specialitate, dacă sunt definite). */
    public function eligibleStaff(Service $service, int $locationId): Collection
    {
        return StaffMember::active()
            ->whereHas('locations', fn ($q) => $q->where('locations.id', $locationId))
            ->when($service->staff_kind, fn ($q, $k) => $q->where('kind', $k))
            ->when($service->specialty_id && $service->staff_kind === 'medic', fn ($q) => $q->whereHas('specialties', fn ($s) => $s->where('specialties.id', $service->specialty_id)))
            ->get();
    }

    /** Programul public al locației (fallback pentru sugestii când persoana nu are program configurat). */
    public function openingIntervals(?Location $location, CarbonImmutable $day): array
    {
        if (! $location) {
            return [];
        }

        return array_map(fn ($i) => [$day->setTimeFromTimeString($i[0]), $day->setTimeFromTimeString($i[1])], $location->hoursFor($day->isoWeekday()));
    }

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $intervals */
    public static function subtract(array $intervals, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach ($intervals as [$a, $b]) {
            if ($to <= $a || $from >= $b) {
                $out[] = [$a, $b];

                continue;
            }
            if ($from > $a) {
                $out[] = [$a, $from];
            }
            if ($to < $b) {
                $out[] = [$to, $b];
            }
        }

        return $out;
    }

    public static function mergeIntervals(array $intervals): array
    {
        $out = [];
        foreach ($intervals as $i) {
            if ($i[0] >= $i[1]) {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $i[0] <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $i[1]);
            } else {
                $out[] = $i;
            }
        }

        return $out;
    }
}
