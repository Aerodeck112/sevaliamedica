<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/** O programare a fost anulată (de recepție sau de pacient prin link); intervalul s-a eliberat. */
class AppointmentCancelled
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment, public readonly bool $byPatient = false) {}
}
