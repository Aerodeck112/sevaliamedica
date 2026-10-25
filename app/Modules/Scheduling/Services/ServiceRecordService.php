<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Events\ServiceRecordChanged;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Înregistrarea serviciilor efectuate. Contract pentru Rehab / Cas / Billing / Reports:
 *  - fromAppointment(): personalul confirmă explicit că serviciul a fost efectuat;
 *  - manual(): serviciu fără programare, cu motiv obligatoriu;
 *  - cancel(): anulare/corectare cu motiv, fără ștergere; opțional cu înregistrare înlocuitoare.
 * Toate emit ServiceRecordChanged după confirmarea tranzacției.
 */
class ServiceRecordService
{
    public const RECORDABLE_STATUSES = [AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE, AppointmentStatus::FINALIZATA];

    public function fromAppointment(Appointment $appointment, array $data, User $user): ServiceRecord
    {
        return DB::transaction(function () use ($appointment, $data, $user) {
            $a = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($a->status, self::RECORDABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['service_id' => 'Serviciul se poate înregistra doar după sosirea pacientului (status actual: '.$a->statusLabel().').']);
            }
            if (! $a->patient_id) {
                throw ValidationException::withMessages(['service_id' => 'Identificați întâi pacientul programării.']);
            }
            $serviceId = (int) ($data['service_id'] ?? $a->service_id);
            if (ServiceRecord::where('appointment_id', $a->id)->where('service_id', $serviceId)->done()->exists()) {
                throw ValidationException::withMessages(['service_id' => 'Acest serviciu este deja înregistrat pentru programare. Pentru o greșeală, folosiți „Anulează / corectează”.']);
            }

            return $this->create([
                'patient_id' => $a->patient_id,
                'appointment_id' => $a->id,
                'service_id' => $serviceId,
                'staff_member_id' => (int) ($data['staff_member_id'] ?? $a->staff_member_id),
                'location_id' => (int) $a->location_id,
                'performed_at' => isset($data['performed_at']) ? CarbonImmutable::parse($data['performed_at']) : ($a->starts_at ?? now()),
                'quantity' => (int) ($data['quantity'] ?? 1),
                'payer_type' => $data['payer_type'] ?? $a->payer_type ?? 'privat',
                'insurer_name' => $data['insurer_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $user);
        });
    }

    public function manual(Patient $patient, array $data, User $user): ServiceRecord
    {
        if (mb_strlen(trim((string) ($data['manual_reason'] ?? ''))) < 5) {
            throw ValidationException::withMessages(['manual_reason' => 'Indicați de ce serviciul nu provine dintr-o programare.']);
        }

        return DB::transaction(fn () => $this->create([
            'patient_id' => $patient->id,
            'appointment_id' => null,
            'service_id' => (int) $data['service_id'],
            'staff_member_id' => (int) $data['staff_member_id'],
            'location_id' => (int) $data['location_id'],
            'performed_at' => CarbonImmutable::parse($data['performed_at']),
            'quantity' => (int) ($data['quantity'] ?? 1),
            'payer_type' => $data['payer_type'],
            'insurer_name' => $data['insurer_name'] ?? null,
            'manual_reason' => trim($data['manual_reason']),
            'notes' => $data['notes'] ?? null,
        ], $user));
    }

    /**
     * Anulează o înregistrare (status → anulat). Cu $replacement se creează înregistrarea corectă, legată de cea veche.
     */
    public function cancel(ServiceRecord $record, string $reason, User $user, ?array $replacement = null): ?ServiceRecord
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['cancel_reason' => 'Motivul anulării/corectării este obligatoriu.']);
        }

        return DB::transaction(function () use ($record, $reason, $user, $replacement) {
            $locked = ServiceRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ServiceRecord::STATUS_DONE) {
                throw ValidationException::withMessages(['cancel_reason' => 'Înregistrarea este deja anulată.']);
            }
            $locked->forceFill(['status' => ServiceRecord::STATUS_CANCELLED, 'cancel_reason' => trim($reason), 'cancelled_by' => $user->id, 'cancelled_at' => now()])->save();
            Audit::log('scheduling.service_record_cancelled', $locked, [], $locked->patient_id);
            DB::afterCommit(fn () => event(new ServiceRecordChanged($locked, 'cancelled')));

            $new = null;
            if ($replacement) {
                $new = $this->create(array_merge($locked->only([
                    'patient_id', 'appointment_id', 'service_id', 'staff_member_id', 'location_id', 'performed_at', 'quantity', 'payer_type', 'insurer_name', 'manual_reason', 'notes',
                ]), array_filter($replacement, fn ($v) => $v !== null && $v !== ''), ['corrects_id' => $locked->id]), $user);
                $locked->forceFill(['corrected_by_id' => $new->id])->save();
            }

            return $new;
        });
    }

    private function create(array $attributes, User $user): ServiceRecord
    {
        if (! array_key_exists($attributes['payer_type'], ServiceRecord::PAYER_TYPES)) {
            throw ValidationException::withMessages(['payer_type' => 'Sursa de plată nu este validă.']);
        }
        $record = ServiceRecord::create($attributes + ['status' => ServiceRecord::STATUS_DONE, 'recorded_by' => $user->id]);
        Audit::log('scheduling.service_recorded', $record, ['sursa_plata' => $record->payer_type, 'corecteaza' => $record->corrects_id], $record->patient_id);
        DB::afterCommit(fn () => event(new ServiceRecordChanged($record, 'recorded')));

        return $record;
    }
}
