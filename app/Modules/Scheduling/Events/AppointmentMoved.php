<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/** Emis după confirmarea tranzacției, când ora, ziua sau persoana unei programări s-au schimbat (ex. pentru notificarea de reprogramare). */
class AppointmentMoved
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment, public readonly ?string $previousStart) {}
}
