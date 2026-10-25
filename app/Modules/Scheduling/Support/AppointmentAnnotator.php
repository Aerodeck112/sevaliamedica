<?php

namespace App\Modules\Scheduling\Support;

use App\Models\User;
use Illuminate\Support\Collection;

interface AppointmentAnnotator
{
    /**
     * @param  Collection<int, \App\Modules\Scheduling\Models\Appointment>  $appointments
     * @return array<int, list<array{label: string, tone: ?string, detail: ?string}>>
     */
    public function annotate(Collection $appointments, User $user): array;
}
