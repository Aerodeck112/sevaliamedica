<?php

namespace App\Modules\Patients\Support;

use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;

/**
 * O sursă de dovadă a relației de îngrijire, oferită de un modul (ex. programări, planuri de recuperare).
 * Se înregistrează cu CareAccess::addEvidence() din boot().
 */
interface CareEvidence
{
    public function establishes(StaffMember $staff, Patient $patient): bool;
}
