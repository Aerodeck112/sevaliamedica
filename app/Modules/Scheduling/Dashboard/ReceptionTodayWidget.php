<?php

namespace App\Modules\Scheduling\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Contracts\View\View;

/** Recepția: programările zilei, sosiri, solicitări noi, anulări recente, lista de așteptare. */
class ReceptionTodayWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'scheduling.manage';
    }

    public function roles(): array
    {
        return [Roles::RECEPTIE, Roles::MANAGER];
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
        $today = Appointment::visibleInCalendar()->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->with(['patient', 'service', 'staffMember', 'location'])->orderBy('starts_at')->get();

        return view('scheduling::dashboard.reception', [
            'today' => $today,
            'counts' => [
                'total' => $today->whereNotIn('status', [AppointmentStatus::ANULATA])->count(),
                'waiting' => $today->where('status', AppointmentStatus::CONFIRMATA)->count(),
                'arrived' => $today->whereIn('status', [AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE])->count(),
                'done' => $today->where('status', AppointmentStatus::FINALIZATA)->count(),
                'no_show' => $today->where('status', AppointmentStatus::NEPREZENTAT)->count(),
            ],
            'requests' => Appointment::where('status', AppointmentStatus::SOLICITARE)->count(),
            'unidentified' => Appointment::where('status', AppointmentStatus::CONFIRMATA)->whereNull('patient_id')->where('starts_at', '>=', now()->startOfDay())->count(),
            'cancellations' => Appointment::where('status', AppointmentStatus::ANULATA)->where('cancelled_at', '>=', now()->subDay())
                ->whereNotNull('starts_at')->with(['patient', 'service'])->latest('cancelled_at')->limit(5)->get(),
            'reschedule' => Appointment::whereNotNull('reschedule_requested_at')->where('status', AppointmentStatus::CONFIRMATA)->count(),
            'waitlist' => WaitlistEntry::open()->count(),
        ]);
    }
}
