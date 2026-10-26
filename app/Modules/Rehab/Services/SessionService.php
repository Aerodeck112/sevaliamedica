<?php

namespace App\Modules\Rehab\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Models\RehabPlanItem;
use App\Modules\Rehab\Models\RehabSessionRecord;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\ServiceRecordService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Marcarea unei ședințe ca efectuată — DOAR prin acțiunea explicită a terapeutului (niciodată la trecerea orei):
 *  1. programarea trece în „finalizată” (pașii obișnuiți azi; confirmare ulterioară, cu motiv, pentru zilele trecute);
 *  2. se creează serviciul efectuat (ServiceRecordService::fromAppointment), cu sursa de plată a procedurii;
 *  3. se înregistrează ședința (observații / evoluție, text liber al terapeutului) și, dacă e cazul, consumul din pachet.
 */
class SessionService
{
    public function __construct(
        private readonly CareAccess $care,
        private readonly BookingService $booking,
        private readonly ServiceRecordService $services,
    ) {}

    public function itemFor(Appointment $appointment): ?RehabPlanItem
    {
        if (! $appointment->series_id) {
            return null;
        }
        $itemId = DB::table('rehab_item_series')->where('appointment_series_id', $appointment->series_id)->value('rehab_plan_item_id');

        return $itemId ? RehabPlanItem::with(['plan.package.definition', 'service'])->find($itemId) : null;
    }

    public function authorize(User $user, Appointment $appointment): RehabPlanItem
    {
        $item = $this->itemFor($appointment);
        abort_unless($item, 404, 'Programarea nu face parte dintr-un plan de recuperare.');
        abort_unless($user->can('rehab.sessions'), 403, 'Nu aveți permisiunea de a marca ședințe.');
        abort_unless(Gate::forUser($user)->allows('recordService', $appointment), 403, 'Doar persoana programată (sau recepția) poate marca ședința.');
        abort_unless($appointment->patient && $this->care->canAccessMedical($user, $appointment->patient), 403, 'Nu aveți o relație de îngrijire cu acest pacient.');

        return $item;
    }

    public function perform(Appointment $appointment, User $user, array $data): RehabSessionRecord
    {
        $item = $this->authorize($user, $appointment);
        $plan = $item->plan;
        if (! in_array($plan->status, [RehabPlan::APPROVED, RehabPlan::ACTIVE], true)) {
            throw ValidationException::withMessages(['status' => 'Planul este „'.$plan->statusLabel().'”; ședințele nu se pot marca.']);
        }
        if (in_array($appointment->status, [AppointmentStatus::ANULATA, AppointmentStatus::NEPREZENTAT, AppointmentStatus::SOLICITARE], true)) {
            throw ValidationException::withMessages(['status' => 'Programarea este „'.$appointment->statusLabel().'” și nu poate fi marcată ca efectuată.']);
        }
        if ($appointment->starts_at && $appointment->starts_at->gt(now()->addMinutes(15))) {
            throw ValidationException::withMessages(['status' => 'Ședința nu a început încă; o puteți marca după ora programării.']);
        }
        if (RehabSessionRecord::where('appointment_id', $appointment->id)->whereHas('serviceRecord', fn ($q) => $q->where('status', 'efectuat'))->exists()) {
            throw ValidationException::withMessages(['status' => 'Ședința este deja înregistrată ca efectuată.']);
        }
        $payer = $item->payer();
        $package = null;
        if ($payer === 'pachet') {
            $package = $plan->package;
            $reason = $package ? $package->blockReason($appointment->starts_at ?? now(), (int) $item->service_id) : 'Planul nu are un pachet atribuit.';
            if ($reason) {
                throw ValidationException::withMessages(['payer' => $reason.' Modificați sursa de plată a procedurii sau atribuiți un pachet valabil.']);
            }
        }
        $isPastDay = $appointment->starts_at && $appointment->starts_at->lt(now()->startOfDay());
        if ($isPastDay && $appointment->status !== AppointmentStatus::FINALIZATA && mb_strlen(trim((string) ($data['late_reason'] ?? ''))) < 5) {
            throw ValidationException::withMessages(['late_reason' => 'Ședința este dintr-o zi trecută: indicați de ce se marchează ulterior.']);
        }

        return DB::transaction(function () use ($appointment, $user, $data, $item, $payer, $package, $isPastDay) {
            $a = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($a->status !== AppointmentStatus::FINALIZATA) {
                if ($isPastDay) {
                    $a = $this->booking->recordLateAttendance($a, $user, (string) $data['late_reason']);
                } else {
                    foreach ([AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE, AppointmentStatus::FINALIZATA] as $step) {
                        if (AppointmentStatus::canTransition($a->status, $step)) {
                            $a = $this->booking->changeStatus($a, $step, $user, 'Ședință marcată efectuată de terapeut');
                        }
                    }
                }
            }
            $record = $this->services->fromAppointment($a, [
                'service_id' => $item->service_id,
                'payer_type' => $payer,
                'staff_member_id' => $a->staff_member_id,
                'performed_at' => $a->starts_at?->toDateTimeString(),
            ], $user);

            // Dacă serviciul unei marcări anterioare a fost anulat/corectat, rândul ședinței se re-leagă de noul serviciu.
            $session = RehabSessionRecord::updateOrCreate(['appointment_id' => $a->id], [
                'rehab_plan_item_id' => $item->id,
                'service_record_id' => $record->id,
                'therapist_staff_id' => $a->staff_member_id,
                'patient_package_id' => $package?->id,
                'performed_at' => $a->starts_at ?? now(),
                'observations' => $this->text($data['observations'] ?? null),
                'evolution' => $this->text($data['evolution'] ?? null),
                'recorded_by' => $user->id,
            ]);
            Audit::log('rehab.session_performed', $session, ['plan' => $item->rehab_plan_id, 'pachet' => $package?->id], $a->patient_id);

            return $session;
        });
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 5000);
    }
}
