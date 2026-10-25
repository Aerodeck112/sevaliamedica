<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/** O solicitare nouă de pe site (pentru notificarea recepției și confirmarea înregistrării către pacient). */
class AppointmentRequestReceived
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment, public readonly bool $autoConfirmed) {}
}
