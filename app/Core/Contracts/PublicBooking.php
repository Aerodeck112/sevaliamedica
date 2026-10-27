<?php

namespace App\Core\Contracts;

use Carbon\CarbonImmutable;

/**
 * Programarea online de pe site: serviciile oferite online și intervalele libere reale. Implementată de modulul
 * Scheduling; modulul Site o folosește fără să cunoască detaliile calendarului. Intervalul ales se trimite apoi
 * prin AppointmentIntake, care decide (după regulile clinicii) dacă îl confirmă sau îl înregistrează ca solicitare.
 */
interface PublicBooking
{
    /** Programarea online este pornită și există cel puțin un serviciu oferit online. */
    public function available(): bool;

    /**
     * Serviciile care se pot programa online, cu locațiile în care există personal potrivit.
     *
     * @return list<array{id: int, name: string, duration: int, specialty: ?string, locations: array<int, string>, staff: array<int, string>}>
     */
    public function services(): array;

    /**
     * Zilele (Y-m-d) cu cel puțin un interval liber, în fereastra de programare online.
     *
     * @return list<string>
     */
    public function days(int $serviceId, int $locationId, ?int $staffId = null, int $count = 14): array;

    /**
     * Intervalele libere ale unei zile. Fără specialist ales, fiecare oră apare o singură dată (primul liber).
     *
     * @return list<array{start: CarbonImmutable, staff_member_id: int, staff: ?string}>
     */
    public function slots(int $serviceId, int $locationId, CarbonImmutable $day, ?int $staffId = null): array;
}
