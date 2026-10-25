<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/** Emis după confirmarea tranzacției, la orice schimbare de status (ex. pentru notificări de confirmare). */
class AppointmentStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment, public readonly string $from, public readonly string $to) {}
}
