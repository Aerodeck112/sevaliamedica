<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/** Emis după confirmarea tranzacției, când o programare nouă a fost creată direct (de recepție sau dintr-o serie). */
class AppointmentBooked
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
