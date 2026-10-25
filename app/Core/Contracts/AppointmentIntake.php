<?php

namespace App\Core\Contracts;

/**
 * Preluarea solicitărilor de programare venite de pe site. Implementată de modulul Scheduling
 * (legată în container). Modulul Site o folosește fără să cunoască detaliile CRM.
 */
interface AppointmentIntake
{
    public function submit(PublicAppointmentRequest $request): IntakeResult;
}
