<?php

namespace App\Modules\Rehab\Services;

use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Models\RehabPlanItem;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evidența ședințelor unei proceduri din plan. Definiții:
 *  - prescrise    = numărul de ședințe din plan;
 *  - efectuate    = ședințe marcate explicit de terapeut, cu serviciul efectuat încă valabil;
 *  - programate   = programări viitoare active (confirmată / sosit / în desfășurare), fără ședință înregistrată;
 *  - de clarificat = programări a căror oră a trecut, active sau finalizate în calendar, dar fără ședință înregistrată
 *                   (nu se consideră efectuate automat);
 *  - anulate / neprezentat = programări cu aceste statusuri (nu consumă ședințe);
 *  - rămase       = prescrise − efectuate;
 *  - neprogramate = rămase − programate − de clarificat.
 */
class SessionProgress
{
    public const ACTIVE = [AppointmentStatus::CONFIRMATA, AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE];

    /**
     * @return array{prescribed:int, done:int, scheduled:int, pending:int, cancelled:int, no_show:int, remaining:int, unscheduled:int, appointments: Collection}
     */
    public function forItem(RehabPlanItem $item): array
    {
        $appointments = $item->appointmentsQuery()->with(['staffMember'])->orderBy('starts_at')->get();
        $records = $item->sessionRecords()->with('serviceRecord')->get()->keyBy('appointment_id');
        $now = now();

        $done = $records->filter(fn ($r) => $r->serviceRecord?->status === ServiceRecord::STATUS_DONE)->count();
        $scheduled = 0;
        $pending = 0;
        $cancelled = 0;
        $noShow = 0;
        foreach ($appointments as $a) {
            $a->setAttribute('rehab_record', $records->get($a->id));
            $recorded = $records->has($a->id) && $records[$a->id]->serviceRecord?->status === ServiceRecord::STATUS_DONE;
            $a->setAttribute('rehab_state', match (true) {
                $recorded => 'efectuata',
                $a->status === AppointmentStatus::ANULATA => 'anulata',
                $a->status === AppointmentStatus::NEPREZENTAT => 'neprezentat',
                $a->starts_at !== null && $a->starts_at->lt($now) => 'de_clarificat',
                default => 'programata',
            });
            match ($a->rehab_state) {
                'anulata' => $cancelled++,
                'neprezentat' => $noShow++,
                'de_clarificat' => $pending++,
                'programata' => $scheduled++,
                default => null,
            };
        }
        $remaining = max(0, $item->sessions_count - $done);

        return [
            'prescribed' => (int) $item->sessions_count,
            'done' => $done,
            'scheduled' => $scheduled,
            'pending' => $pending,
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'remaining' => $remaining,
            'unscheduled' => max(0, $remaining - $scheduled - $pending),
            'appointments' => $appointments,
        ];
    }

    /** Totaluri pentru întregul plan. */
    public function forPlan(RehabPlan $plan): array
    {
        $totals = ['prescribed' => 0, 'done' => 0, 'scheduled' => 0, 'pending' => 0, 'cancelled' => 0, 'no_show' => 0, 'remaining' => 0, 'unscheduled' => 0];
        $items = [];
        foreach ($plan->items as $item) {
            $p = $this->forItem($item);
            $items[$item->id] = $p;
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + $p[$k];
            }
        }

        return ['totals' => $totals, 'items' => $items];
    }

    /** Programările din seriile planurilor, a căror oră a trecut, fără ședință înregistrată (lista „de închis”). */
    public function pendingFor(?int $staffId = null): Collection
    {
        $seriesIds = DB::table('rehab_item_series')->pluck('appointment_series_id');
        if ($seriesIds->isEmpty()) {
            return collect();
        }

        return Appointment::whereIn('series_id', $seriesIds)
            ->where('starts_at', '<', now())
            ->whereNotIn('status', [AppointmentStatus::ANULATA, AppointmentStatus::NEPREZENTAT, AppointmentStatus::SOLICITARE])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rehab_session_records as r')
                ->join('service_records as s', 's.id', '=', 'r.service_record_id')
                ->whereColumn('r.appointment_id', 'appointments.id')->where('s.status', ServiceRecord::STATUS_DONE))
            ->when($staffId, fn ($q) => $q->where('staff_member_id', $staffId))
            ->with(['patient', 'service', 'staffMember'])
            ->orderBy('starts_at')
            ->limit(200)
            ->get();
    }
}
