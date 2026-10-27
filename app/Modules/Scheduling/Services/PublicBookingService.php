<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Contracts\PublicBooking;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Intervalele libere pentru programarea online. Folosește aceeași verificare ca recepția (program, pauze,
 * concedii, săli, aparate, programări existente), în fereastra [avans minim, avans maxim] din setări.
 * Nu rezervă nimic: rezervarea se face la trimitere, cu verificare și blocare (AppointmentIntake).
 */
class PublicBookingService implements PublicBooking
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $booking,
        private readonly SchedulingSettings $settings,
    ) {}

    public function available(): bool
    {
        return (bool) $this->settings->get('scheduling.online.enabled') && $this->services() !== [];
    }

    public function services(): array
    {
        $locations = Location::active()->get();
        $out = [];
        foreach (Service::active()->where('online_booking', true)->with('specialty')->orderBy('name')->get() as $service) {
            $locs = [];
            $staff = [];
            foreach ($locations as $loc) {
                $eligible = $this->availability->eligibleStaff($service, $loc->id);
                if ($eligible->isNotEmpty()) {
                    $locs[$loc->id] = $loc->name;
                    // Pe site se pot alege nominal doar specialiștii afișați public (pagina echipei).
                    foreach ($eligible->where('show_on_site', true) as $s) {
                        $staff[$s->id] = $s->display_name;
                    }
                }
            }
            if ($locs) {
                $out[] = ['id' => $service->id, 'name' => $service->name, 'duration' => (int) $service->duration_minutes,
                    'specialty' => $service->specialty?->name, 'locations' => $locs, 'staff' => $staff];
            }
        }

        return $out;
    }

    public function days(int $serviceId, int $locationId, ?int $staffId = null, int $count = 14): array
    {
        [$from, $until] = $this->window();
        $days = [];
        for ($day = $from->startOfDay(); $day <= $until && count($days) < $count; $day = $day->addDay()) {
            if ($this->slots($serviceId, $locationId, $day, $staffId, 1) !== []) {
                $days[] = $day->toDateString();
            }
            if ($day->diffInDays($from->startOfDay()) > 62) {
                break;
            }
        }

        return $days;
    }

    public function slots(int $serviceId, int $locationId, CarbonImmutable $day, ?int $staffId = null, ?int $limit = null): array
    {
        $service = Service::active()->where('online_booking', true)->find($serviceId);
        if (! $service || ! Location::active()->whereKey($locationId)->exists()) {
            return [];
        }
        $eligible = $this->availability->eligibleStaff($service, $locationId);
        $public = $eligible->where('show_on_site', true)->pluck('display_name', 'id');
        if ($staffId !== null && ! $public->has($staffId)) {
            return [];
        }
        [$from, $until] = $this->window();
        $start = $day->startOfDay()->max($from);
        if ($start > $until || $start->toDateString() !== $day->toDateString()) {
            return [];
        }

        // Rezultatul unei zile se păstrează 60 s: pagina se reîncarcă des, iar trimiterea reverifică oricum.
        $key = 'public-slots:'.implode(':', [$serviceId, $locationId, $staffId ?? 0, $day->toDateString(), $limit ?? 0]);
        $raw = Cache::remember($key, 60, function () use ($service, $locationId, $staffId, $start, $until, $limit) {
            $template = new BookingRequest(0, $service->id, $staffId, $locationId, $start, source: 'site');
            $found = $this->availability->suggest($this->booking, $template, null, $limit ? $limit * 4 : 400, 1);

            return array_map(fn ($s) => [$s['start']->toIso8601String(), $s['staff_member_id']],
                array_filter($found, fn ($s) => $s['start'] <= $until && $s['start']->toDateString() === $start->toDateString()));
        });

        // Online se oferă doar orele din programul public al locației (dacă e completat), chiar dacă personalul
        // are program și în afara lui (ex. sâmbăta, pentru programări făcute de recepție).
        $location = Location::find($locationId);
        $hasHours = $location && collect(range(1, 7))->contains(fn ($d) => $location->hoursFor($d) !== []);
        $open = $hasHours ? $this->availability->openingIntervals($location, $day->startOfDay()) : null;
        $duration = (int) $service->duration_minutes;

        $out = [];
        foreach ($raw as [$iso, $staff]) {
            $time = CarbonImmutable::parse($iso);
            if ($open !== null && ! collect($open)->contains(fn ($i) => $time >= $i[0] && $time->addMinutes($duration) <= $i[1])) {
                continue;
            }
            $k = $staffId === null ? $time->format('H:i') : $time->format('H:i').'#'.$staff;
            $out[$k] ??= ['start' => $time, 'staff_member_id' => (int) $staff, 'staff' => $public[(int) $staff] ?? null];
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $limit ? array_slice($out, 0, $limit) : $out;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(): array
    {
        $now = CarbonImmutable::now();

        return [$now->addHours((int) $this->settings->get('scheduling.online.min_advance_hours')),
            $now->addDays((int) $this->settings->get('scheduling.online.max_advance_days'))];
    }
}
