<?php

namespace App\Modules\Scheduling\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Identity\Roles;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Contracts\View\View;

/** Medic / terapeut / asistent: pacienții zilei și seriile active ale persoanei. */
class MyPatientsTodayWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'scheduling.attend';
    }

    public function roles(): array
    {
        return Roles::clinical();
    }

    public function order(): int
    {
        return 10;
    }

    public function span(): int
    {
        return 2;
    }

    public function render(User $user): View
    {
        $staff = StaffMember::where('user_id', $user->id)->first();
        $today = $staff ? Appointment::visibleInCalendar()->where('staff_member_id', $staff->id)
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->where('status', '!=', AppointmentStatus::ANULATA)
            ->with(['patient', 'service', 'location'])->orderBy('starts_at')->get() : collect();
        $series = $staff ? AppointmentSeries::where('staff_member_id', $staff->id)->where('status', 'activa')->with(['patient', 'service'])->latest()->limit(8)->get() : collect();

        return view('scheduling::dashboard.my-day', ['staff' => $staff, 'today' => $today, 'series' => $series]);
    }
}
