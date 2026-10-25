<?php

namespace App\Modules\Scheduling\Policies;

use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\AppointmentStatus;

/**
 * - scheduling.manage (recepție, manager): orice tranziție permisă, mutări, anulări.
 * - scheduling.attend (medic, terapeut, asistent): sosit / în desfășurare / finalizată doar pentru programările proprii.
 */
class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->can('scheduling.view');
    }

    public function manage(User $user, Appointment $appointment): bool
    {
        return $user->can('scheduling.manage');
    }

    public function transition(User $user, Appointment $appointment, string $to): bool
    {
        if ($user->can('scheduling.manage')) {
            return true;
        }
        if (! $user->can('scheduling.attend') || ! in_array($to, [AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE, AppointmentStatus::FINALIZATA], true)) {
            return false;
        }

        return $this->isOwn($user, $appointment);
    }

    public function recordService(User $user, Appointment $appointment): bool
    {
        if (! $user->can('services.record')) {
            return false;
        }

        return $user->can('scheduling.manage') || $this->isOwn($user, $appointment);
    }

    public function isOwn(User $user, Appointment $appointment): bool
    {
        $staffId = StaffMember::where('user_id', $user->id)->value('id');

        return $staffId !== null && (int) $appointment->staff_member_id === (int) $staffId;
    }
}
