<?php

namespace App\Modules\Rehab\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Identity\Roles;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Services\SessionProgress;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/** Terapeut: procedurile zilei din planuri, ședințele trecute nemarcate și planurile active. */
class TherapistWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'rehab.sessions';
    }

    public function roles(): array
    {
        return [Roles::TERAPEUT, Roles::ASISTENT];
    }

    public function order(): int
    {
        return 12;
    }

    public function span(): int
    {
        return 2;
    }

    public function render(User $user): View
    {
        $staff = StaffMember::where('user_id', $user->id)->first();
        $seriesIds = DB::table('rehab_item_series')->pluck('appointment_series_id');
        $today = $staff && $seriesIds->isNotEmpty() ? Appointment::whereIn('series_id', $seriesIds)->where('staff_member_id', $staff->id)
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', [AppointmentStatus::ANULATA, AppointmentStatus::SOLICITARE])
            ->with(['patient', 'service'])->orderBy('starts_at')->get() : collect();
        $pending = $staff ? app(SessionProgress::class)->pendingFor($staff->id)->filter(fn ($a) => ! $a->starts_at->isToday()) : collect();
        $plans = $staff ? RehabPlan::whereIn('status', [RehabPlan::APPROVED, RehabPlan::ACTIVE])
            ->whereHas('items', fn ($q) => $q->where('preferred_therapist_id', $staff->id))
            ->with(['patient', 'items.service'])->latest()->limit(8)->get() : collect();
        $done = DB::table('rehab_session_records')->whereIn('appointment_id', $today->pluck('id'))->pluck('appointment_id')->flip();

        return view('rehab::dashboard.therapist', compact('staff', 'today', 'pending', 'plans', 'done'));
    }
}
