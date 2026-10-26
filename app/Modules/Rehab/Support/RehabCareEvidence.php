<?php

namespace App\Modules\Rehab\Support;

use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\CareEvidence;
use App\Modules\Rehab\Models\RehabPlan;
use Illuminate\Support\Facades\DB;

/**
 * Relație de îngrijire dovedită de un plan de recuperare deschis: medicul care l-a întocmit sau terapeutul
 * preferat al unei proceduri. Un plan încheiat nu mai acordă acces (accesul ulterior trece prin programări sau
 * relații explicite).
 */
class RehabCareEvidence implements CareEvidence
{
    public function establishes(StaffMember $staff, Patient $patient): bool
    {
        $open = [RehabPlan::DRAFT, RehabPlan::APPROVED, RehabPlan::ACTIVE, RehabPlan::SUSPENDED];

        if (RehabPlan::where('patient_id', $patient->id)->whereIn('status', $open)->where('physician_staff_id', $staff->id)->exists()) {
            return true;
        }

        return DB::table('rehab_plan_items as i')->join('rehab_plans as p', 'p.id', '=', 'i.rehab_plan_id')
            ->where('p.patient_id', $patient->id)->whereIn('p.status', $open)
            ->where('i.preferred_therapist_id', $staff->id)->exists();
    }
}
