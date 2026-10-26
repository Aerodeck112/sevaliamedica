<?php

namespace App\Modules\Rehab\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Clinic\Models\Service;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Rehab\Models\PatientPackage;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Models\RehabPlanItem;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\SeriesScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclul planului de recuperare. Contract pentru CAS / Billing / Reports:
 *  - planurile și procedurile au sursa de plată (privat | cas | pachet | asigurator);
 *  - ședințele efectuate sunt `rehab_session_records` legate de `service_records` (pivotul comun).
 * Planul este întocmit și aprobat de clinician; aplicația nu propune proceduri sau număr de ședințe.
 */
class PlanService
{
    public function __construct(
        private readonly CareAccess $care,
        private readonly SeriesScheduler $series,
        private readonly BookingService $booking,
        private readonly SessionProgress $progress,
    ) {}

    public function create(Patient $patient, User $user, array $data): RehabPlan
    {
        $this->assertCare($user, $patient, 'rehab.plan');
        $staff = $this->care->staffFor($user);
        if (! $staff) {
            throw ValidationException::withMessages(['patient' => 'Contul nu este asociat unei persoane din personal (Administrare → Personal).']);
        }
        if (! empty($data['medical_record_id']) && ! MedicalRecord::whereKey($data['medical_record_id'])->where('patient_id', $patient->id)->exists()) {
            throw ValidationException::withMessages(['medical_record_id' => 'Consultația nu aparține pacientului.']);
        }
        $this->assertPackage($patient, $data);

        $plan = RehabPlan::create([
            'patient_id' => $patient->id,
            'medical_record_id' => $data['medical_record_id'] ?? null,
            'physician_staff_id' => $staff->id,
            'location_id' => $data['location_id'],
            'status' => RehabPlan::DRAFT,
            'payer_type' => $data['payer_type'],
            'patient_package_id' => $data['patient_package_id'] ?? null,
            'objectives' => $data['objectives'] ?? null,
            'notes' => $data['notes'] ?? null,
            'planned_start' => $data['planned_start'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        Audit::log('rehab.plan_created', $plan, [], $patient->id);

        return $plan;
    }

    public function updateDraft(RehabPlan $plan, User $user, array $data): RehabPlan
    {
        $this->assertCare($user, $plan->patient, 'rehab.plan');
        $this->assertDraft($plan);
        $this->assertPackage($plan->patient, $data);
        $plan->update([
            'location_id' => $data['location_id'],
            'payer_type' => $data['payer_type'],
            'patient_package_id' => $data['patient_package_id'] ?? null,
            'objectives' => $data['objectives'] ?? null,
            'notes' => $data['notes'] ?? null,
            'planned_start' => $data['planned_start'] ?? null,
            'medical_record_id' => $data['medical_record_id'] ?? $plan->medical_record_id,
            'updated_by' => $user->id,
            'version' => $plan->version + 1,
        ]);
        Audit::log('rehab.plan_updated', $plan, [], $plan->patient_id);

        return $plan;
    }

    public function addItem(RehabPlan $plan, User $user, array $data): RehabPlanItem
    {
        $this->assertCare($user, $plan->patient, 'rehab.plan');
        $this->assertDraft($plan);
        $service = Service::findOrFail($data['service_id']);
        if (! $service->is_active) {
            throw ValidationException::withMessages(['service_id' => 'Serviciul nu este activ în catalog.']);
        }
        $item = $plan->items()->create([
            'service_id' => $service->id,
            'sessions_count' => (int) $data['sessions_count'],
            'sessions_per_week' => $data['sessions_per_week'] ?? null,
            'preferred_therapist_id' => $data['preferred_therapist_id'] ?? null,
            'payer_type' => $data['payer_type'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'sort' => (int) $plan->items()->max('sort') + 10,
        ]);
        Audit::log('rehab.plan_item_added', $plan, ['serviciu' => $service->id, 'sedinte' => $item->sessions_count], $plan->patient_id);

        return $item;
    }

    public function removeItem(RehabPlanItem $item, User $user): void
    {
        $plan = $item->plan;
        $this->assertCare($user, $plan->patient, 'rehab.plan');
        $this->assertDraft($plan);
        $item->delete();
        Audit::log('rehab.plan_item_removed', $plan, ['procedura' => $item->id], $plan->patient_id);
    }

    public function approve(RehabPlan $plan, User $user): RehabPlan
    {
        $this->assertCare($user, $plan->patient, 'rehab.approve');
        $this->assertDraft($plan);
        $staff = $this->care->staffFor($user);
        if (! $staff || $staff->kind !== 'medic') {
            throw ValidationException::withMessages(['status' => 'Planul se aprobă de un medic (persoană din personal cu categoria „Medic”).']);
        }
        if ($plan->items()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'Adăugați cel puțin o procedură înainte de aprobare.']);
        }
        $plan->update(['status' => RehabPlan::APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_by' => $user->id, 'version' => $plan->version + 1]);
        Audit::log('rehab.plan_approved', $plan, [], $plan->patient_id);

        return $plan;
    }

    /**
     * Prelungire după aprobare: numai numărul de ședințe al unei proceduri, de un medic cu rehab.approve, cu motiv.
     */
    public function extendItem(RehabPlanItem $item, User $user, int $sessions, string $reason): RehabPlanItem
    {
        $plan = $item->plan;
        $this->assertCare($user, $plan->patient, 'rehab.approve');
        if (! in_array($plan->status, [RehabPlan::APPROVED, RehabPlan::ACTIVE, RehabPlan::SUSPENDED], true)) {
            throw ValidationException::withMessages(['sessions_count' => 'Doar un plan aprobat și neîncheiat poate fi modificat astfel.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Motivul modificării este obligatoriu.']);
        }
        $done = $this->progress->forItem($item)['done'];
        if ($sessions < max(1, $done)) {
            throw ValidationException::withMessages(['sessions_count' => "Numărul de ședințe nu poate fi mai mic decât cele efectuate ({$done})."]);
        }
        $old = $item->sessions_count;
        $item->update(['sessions_count' => $sessions]);
        Audit::log('rehab.plan_item_changed', $plan, ['procedura' => $item->id, 'din' => $old, 'in' => $sessions], $plan->patient_id);
        $plan->increment('version');

        return $item;
    }

    public function suspend(RehabPlan $plan, User $user, string $reason): RehabPlan
    {
        $this->assertCare($user, $plan->patient, 'rehab.approve');
        if (! in_array($plan->status, [RehabPlan::APPROVED, RehabPlan::ACTIVE], true)) {
            throw ValidationException::withMessages(['status' => 'Doar un plan aprobat sau în desfășurare poate fi suspendat.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Motivul suspendării este obligatoriu.']);
        }
        $plan->update(['status' => RehabPlan::SUSPENDED, 'suspended_at' => now(), 'suspend_reason' => trim($reason), 'updated_by' => $user->id]);
        Audit::log('rehab.plan_suspended', $plan, [], $plan->patient_id);

        return $plan;
    }

    public function resume(RehabPlan $plan, User $user): RehabPlan
    {
        $this->assertCare($user, $plan->patient, 'rehab.approve');
        if ($plan->status !== RehabPlan::SUSPENDED) {
            throw ValidationException::withMessages(['status' => 'Planul nu este suspendat.']);
        }
        $hasSeries = DB::table('rehab_item_series')->whereIn('rehab_plan_item_id', $plan->items()->pluck('id'))->exists();
        $plan->update(['status' => $hasSeries ? RehabPlan::ACTIVE : RehabPlan::APPROVED, 'suspended_at' => null, 'updated_by' => $user->id]);
        Audit::log('rehab.plan_resumed', $plan, [], $plan->patient_id);

        return $plan;
    }

    /**
     * Închiderea planului: concluzia clinicianului e obligatorie; ședințele „de clarificat” trebuie rezolvate întâi.
     * Ședințele viitoare se anulează (cu motiv) numai dacă utilizatorul confirmă explicit.
     */
    public function close(RehabPlan $plan, User $user, string $conclusion, bool $cancelFuture): RehabPlan
    {
        $this->assertCare($user, $plan->patient, 'rehab.approve');
        if (! in_array($plan->status, [RehabPlan::APPROVED, RehabPlan::ACTIVE, RehabPlan::SUSPENDED], true)) {
            throw ValidationException::withMessages(['status' => 'Planul nu poate fi încheiat din starea actuală.']);
        }
        if (mb_strlen(trim($conclusion)) < 5) {
            throw ValidationException::withMessages(['conclusion' => 'Concluzia la închidere este obligatorie.']);
        }
        $progress = $this->progress->forPlan($plan->load('items'));
        if ($progress['totals']['pending'] > 0) {
            throw ValidationException::withMessages(['status' => 'Există '.$progress['totals']['pending'].' ședințe trecute nemarcate. Marcați-le ca efectuate sau rezolvați programările (neprezentat / anulată) înainte de închidere.']);
        }
        if ($progress['totals']['scheduled'] > 0 && ! $cancelFuture) {
            throw ValidationException::withMessages(['cancel_future' => 'Planul are '.$progress['totals']['scheduled'].' ședințe programate în viitor. Confirmați anularea lor pentru a încheia planul.']);
        }

        return DB::transaction(function () use ($plan, $user, $conclusion, $progress) {
            foreach ($progress['items'] as $p) {
                foreach ($p['appointments'] as $a) {
                    if ($a->rehab_state === 'programata') {
                        $this->booking->changeStatus($a, \App\Modules\Scheduling\Support\AppointmentStatus::ANULATA, $user, 'Planul de recuperare a fost încheiat');
                    }
                }
            }
            $plan->update(['status' => RehabPlan::CLOSED, 'closed_at' => now(), 'closed_by' => $user->id, 'closing_conclusion' => trim($conclusion), 'updated_by' => $user->id]);
            DB::table('appointment_series')->whereIn('id', DB::table('rehab_item_series')->whereIn('rehab_plan_item_id', $plan->items()->pluck('id'))->pluck('appointment_series_id'))
                ->update(['status' => 'inchisa', 'updated_at' => now()]);
            Audit::log('rehab.plan_closed', $plan, ['efectuate' => $progress['totals']['done'], 'prescrise' => $progress['totals']['prescribed']], $plan->patient_id);

            return $plan;
        });
    }

    /**
     * Programează ședințele unei proceduri prin SeriesScheduler (cu conflicte, alternative, creare parțială doar cu acord).
     *
     * @return array{series: \App\Modules\Scheduling\Models\AppointmentSeries, created: list, failed: list}
     */
    public function scheduleItem(RehabPlanItem $item, User $user, array $rule, array $choices, ?int $staffId, bool $allowPartial): array
    {
        abort_unless($user->can('rehab.schedule'), 403);
        $plan = $item->plan;
        if (! $plan->canSchedule()) {
            throw ValidationException::withMessages(['series' => 'Se pot programa doar ședințele unui plan aprobat sau în desfășurare.']);
        }
        $unscheduled = $this->progress->forItem($item)['unscheduled'];
        if ((int) $rule['count'] > $unscheduled) {
            throw ValidationException::withMessages(['count' => "Procedura mai are {$unscheduled} ședințe neprogramate."]);
        }

        return DB::transaction(function () use ($item, $user, $rule, $choices, $staffId, $allowPartial, $plan) {
            $result = $this->series->create((int) $plan->patient_id, (int) $item->service_id, $staffId, (int) $plan->location_id, $rule, $choices, $user, $allowPartial, 'Plan de recuperare #'.$plan->id);
            DB::table('rehab_item_series')->insert(['rehab_plan_item_id' => $item->id, 'appointment_series_id' => $result['series']->id, 'created_at' => now()]);
            // Sursa de plată estimată a programărilor = cea a procedurii.
            DB::table('appointments')->where('series_id', $result['series']->id)->update(['payer_type' => $item->payer()]);
            if ($plan->status === RehabPlan::APPROVED) {
                $plan->update(['status' => RehabPlan::ACTIVE]);
            }
            Audit::log('rehab.item_scheduled', $plan, ['procedura' => $item->id, 'serie' => $result['series']->id, 'sedinte' => count($result['created'])], $plan->patient_id);

            return $result;
        });
    }

    public function assertCare(User $user, Patient $patient, string $permission): void
    {
        abort_unless($user->can($permission), 403, 'Nu aveți permisiunea necesară.');
        abort_unless($this->care->canAccessMedical($user, $patient), 403, 'Nu aveți o relație de îngrijire cu acest pacient.');
    }

    private function assertDraft(RehabPlan $plan): void
    {
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Planul a fost aprobat; nu se mai modifică (cel mult numărul de ședințe, cu motiv).']);
        }
    }

    private function assertPackage(Patient $patient, array $data): void
    {
        if (($data['payer_type'] ?? null) === 'pachet') {
            if (empty($data['patient_package_id'])) {
                throw ValidationException::withMessages(['patient_package_id' => 'Alegeți pachetul pacientului.']);
            }
            if (! PatientPackage::whereKey($data['patient_package_id'])->where('patient_id', $patient->id)->where('status', 'activ')->exists()) {
                throw ValidationException::withMessages(['patient_package_id' => 'Pachetul nu aparține pacientului sau nu este activ.']);
            }
        }
        if (isset($data['payer_type']) && ! array_key_exists($data['payer_type'], ServiceRecord::PAYER_TYPES)) {
            throw ValidationException::withMessages(['payer_type' => 'Sursa de plată nu este validă.']);
        }
    }
}
