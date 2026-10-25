<?php

namespace App\Modules\Scheduling\Support;

use Carbon\CarbonImmutable;

/**
 * Datele unei rezervări. Durata implicită vine din serviciu; endsAt o poate suprascrie (ex. redimensionare).
 * roomIds/equipmentIds: alegere manuală; altfel se alocă automat resurse libere de tipul cerut de serviciu.
 */
final class BookingRequest
{
    public function __construct(
        public readonly int $patientId,
        public readonly int $serviceId,
        public readonly ?int $staffMemberId,
        public readonly int $locationId,
        public readonly CarbonImmutable $startsAt,
        public readonly ?CarbonImmutable $endsAt = null,
        public readonly array $roomIds = [],
        public readonly array $equipmentIds = [],
        public readonly string $status = AppointmentStatus::CONFIRMATA,
        public readonly string $source = 'receptie',
        public readonly ?string $payerType = null,
        public readonly ?string $notes = null,
        /** Permite rezervarea în afara programului de lucru (cere permisiunea scheduling.override și motiv). */
        public readonly bool $overrideSchedule = false,
        public readonly ?string $overrideReason = null,
        public readonly ?int $seriesId = null,
        public readonly ?int $seriesIndex = null,
    ) {}

    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
