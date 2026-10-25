<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serii de ședințe (programări repetate). Contract public pentru modulul Rehab:
 *
 *  - plan($rule, ...)            → propunerea de ședințe, fiecare cu conflictele și alternative libere;
 *  - create($rule, $choices)     → creează seria cu ședințele alese (parțial doar cu acord explicit);
 *  - planReschedule($series, …)  → propunere pentru mutarea tuturor ședințelor viitoare;
 *  - applyReschedule($series, …) → aplică mutarea aleasă;
 *  - o singură ședință se reprogramează cu BookingService::move().
 *
 * Regula: ['start_date' => 'Y-m-d', 'weekdays' => [1,3,5], 'time' => 'H:i', 'count' => 10].
 */
class SeriesScheduler
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @param  array{start_date: string, weekdays: list<int>, time: string, count: int}  $rule
     * @return list<array{index: int, start: CarbonImmutable, end: CarbonImmutable, conflicts: list<string>, warnings: list<string>, alternatives: list<array>}>
     */
    public function plan(int $patientId, int $serviceId, ?int $staffId, int $locationId, array $rule, array $excludeByIndex = []): array
    {
        $this->assertRule($rule);
        $items = [];
        foreach ($this->dates($rule) as $i => $start) {
            $request = new BookingRequest($patientId, $serviceId, $staffId, $locationId, $start, status: AppointmentStatus::CONFIRMATA, source: 'serie');
            $eval = $this->booking->evaluate($request, $excludeByIndex[$i] ?? null);
            $alternatives = [];
            if ($eval['conflicts']) {
                $alternatives = $this->availability->suggest($this->booking, $request->with(['startsAt' => $start->startOfDay()]), $excludeByIndex[$i] ?? null, 3, 3);
            }
            $items[] = [
                'index' => $i + 1,
                'start' => $start,
                'end' => $eval['end'] ?? $start,
                'conflicts' => array_map(fn ($c) => $c->message, $eval['conflicts']),
                'warnings' => $eval['warnings'],
                'alternatives' => $alternatives,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{index: int, start: string, skip?: bool}>  $choices  ședințele alese (ora poate fi o alternativă)
     * @param  bool  $allowPartial  acordul explicit pentru a crea seria chiar dacă unele ședințe nu se pot rezerva
     * @return array{series: AppointmentSeries, created: list<Appointment>, failed: list<array{index:int, start:string, reasons: list<string>}>}
     */
    public function create(int $patientId, int $serviceId, ?int $staffId, int $locationId, array $rule, array $choices, User $user, bool $allowPartial = false, ?string $notes = null): array
    {
        $this->assertRule($rule);

        return DB::transaction(function () use ($patientId, $serviceId, $staffId, $locationId, $rule, $choices, $user, $allowPartial, $notes) {
            $series = AppointmentSeries::create([
                'patient_id' => $patientId, 'service_id' => $serviceId, 'staff_member_id' => $staffId,
                'location_id' => $locationId, 'rule' => $rule, 'planned_count' => (int) $rule['count'],
                'notes' => $notes, 'created_by' => $user->id,
            ]);
            $created = [];
            $failed = [];
            foreach ($choices as $choice) {
                if (! empty($choice['skip'])) {
                    $failed[] = ['index' => (int) $choice['index'], 'start' => (string) $choice['start'], 'reasons' => ['omisă la cererea utilizatorului']];

                    continue;
                }
                $start = CarbonImmutable::parse($choice['start']);
                try {
                    $created[] = $this->booking->book(new BookingRequest(
                        $patientId, $serviceId, (int) ($choice['staff_member_id'] ?? $staffId) ?: null, $locationId, $start,
                        status: AppointmentStatus::CONFIRMATA, source: 'serie', seriesId: $series->id, seriesIndex: (int) $choice['index'],
                    ), $user);
                } catch (BookingConflictException $e) {
                    $failed[] = ['index' => (int) $choice['index'], 'start' => $start->format('Y-m-d H:i'), 'reasons' => $e->messages()];
                }
            }
            if ($failed && ! $allowPartial) {
                throw ValidationException::withMessages(['series' => 'Unele ședințe nu au putut fi rezervate. Nimic nu a fost salvat. Alegeți alternative sau confirmați crearea parțială.']);
            }
            if (! $created) {
                throw ValidationException::withMessages(['series' => 'Nicio ședință nu a putut fi rezervată.']);
            }
            Audit::log('scheduling.series_created', $series, ['sedinte' => count($created), 'nerezervate' => count($failed)], $patientId);

            return ['series' => $series, 'created' => $created, 'failed' => $failed];
        });
    }

    /** Ședințele viitoare care se pot muta (confirmate, de la data dată). */
    public function futureAppointments(AppointmentSeries $series, CarbonImmutable $from)
    {
        return $series->appointments()->where('status', AppointmentStatus::CONFIRMATA)->where('starts_at', '>=', $from)->get();
    }

    /** Propunere pentru mutarea tuturor ședințelor viitoare după o regulă nouă. */
    public function planReschedule(AppointmentSeries $series, array $rule, ?int $staffId = null): array
    {
        $future = $this->futureAppointments($series, CarbonImmutable::parse($rule['start_date'])->startOfDay());
        $rule['count'] = $future->count();
        if ($rule['count'] === 0) {
            return [];
        }
        $exclude = $future->values()->map->id->all();
        $plan = $this->plan((int) $series->patient_id, (int) $series->service_id, $staffId ?? $series->staff_member_id, (int) $series->location_id, $rule, $exclude);
        foreach ($plan as $i => &$item) {
            $item['appointment_id'] = $exclude[$i];
            $item['current_start'] = $future[$i]->starts_at;
        }

        return $plan;
    }

    /**
     * @param  list<array{appointment_id: int, start: string, skip?: bool}>  $choices
     * @return array{moved: int, failed: list<array>}
     */
    public function applyReschedule(AppointmentSeries $series, array $choices, ?int $staffId, User $user, string $reason): array
    {
        $moved = 0;
        $failed = [];
        foreach ($choices as $choice) {
            if (! empty($choice['skip'])) {
                continue;
            }
            $appointment = Appointment::where('series_id', $series->id)->findOrFail((int) $choice['appointment_id']);
            try {
                $this->booking->move($appointment, CarbonImmutable::parse($choice['start']), null, $staffId ?? $appointment->staff_member_id, null, $user, $reason);
                $moved++;
            } catch (BookingConflictException $e) {
                $failed[] = ['appointment_id' => $appointment->id, 'start' => $choice['start'], 'reasons' => $e->messages()];
            }
        }
        if ($staffId && $staffId !== $series->staff_member_id) {
            $series->update(['staff_member_id' => $staffId]);
        }
        Audit::log('scheduling.series_rescheduled', $series, ['mutate' => $moved, 'esuate' => count($failed)], $series->patient_id);

        return ['moved' => $moved, 'failed' => $failed];
    }

    /** @return list<CarbonImmutable> */
    public function dates(array $rule): array
    {
        $date = CarbonImmutable::parse($rule['start_date'])->startOfDay();
        $weekdays = array_map('intval', $rule['weekdays']);
        [$h, $m] = array_map('intval', explode(':', $rule['time']));
        $out = [];
        for ($i = 0; $i < 730 && count($out) < (int) $rule['count']; $i++) {
            $day = $date->addDays($i);
            if (in_array($day->isoWeekday(), $weekdays, true)) {
                $out[] = $day->setTime($h, $m);
            }
        }

        return $out;
    }

    private function assertRule(array $rule): void
    {
        if (empty($rule['weekdays']) || empty($rule['time']) || empty($rule['start_date']) || (int) ($rule['count'] ?? 0) < 1 || (int) $rule['count'] > 100) {
            throw ValidationException::withMessages(['rule' => 'Regula seriei este incompletă (zile, oră, dată de început, 1–100 ședințe).']);
        }
    }
}
