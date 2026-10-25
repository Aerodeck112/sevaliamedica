<?php

namespace App\Modules\Scheduling\Support;

use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Patients\Support\CareEvidence;
use App\Modules\Scheduling\Models\Appointment;

/**
 * Relație de îngrijire dovedită de o programare (neanulată) a persoanei cu pacientul, în fereastra configurată.
 */
class AppointmentCareEvidence implements CareEvidence
{
    public function establishes(StaffMember $staff, Patient $patient): bool
    {
        return Appointment::where('patient_id', $patient->id)
            ->where('staff_member_id', $staff->id)
            ->whereNotIn('status', [AppointmentStatus::ANULATA, AppointmentStatus::SOLICITARE])
            ->whereBetween('starts_at', [
                now()->subDays(CareAccess::setting('care_window_past_days')),
                now()->addDays(CareAccess::setting('care_window_future_days')),
            ])->exists();
    }
}
