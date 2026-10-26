<?php

namespace App\Modules\Medical\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Identity\Roles;
use App\Modules\Medical\Models\MedicalRecord;
use Illuminate\Contracts\View\View;

/** Medic: fișele proprii rămase în draft (de finalizat). */
class DraftRecordsWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'medical.write';
    }

    public function roles(): array
    {
        return [Roles::MEDIC];
    }

    public function order(): int
    {
        return 15;
    }

    public function render(User $user): View
    {
        $staff = StaffMember::where('user_id', $user->id)->first();
        $drafts = $staff ? MedicalRecord::where('staff_member_id', $staff->id)->where('status', MedicalRecord::DRAFT)
            ->with('patient')->orderBy('created_at')->limit(8)->get() : collect();
        $total = $staff ? MedicalRecord::where('staff_member_id', $staff->id)->where('status', MedicalRecord::DRAFT)->count() : 0;

        return view('medical::dashboard.drafts', ['drafts' => $drafts, 'total' => $total, 'staff' => $staff]);
    }
}
