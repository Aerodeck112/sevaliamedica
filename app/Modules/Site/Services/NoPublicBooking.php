<?php

namespace App\Modules\Site\Services;

use App\Core\Contracts\PublicBooking;
use Carbon\CarbonImmutable;

/** Implementarea implicită când modulul de programări lipsește: programarea online nu este disponibilă. */
class NoPublicBooking implements PublicBooking
{
    public function available(): bool
    {
        return false;
    }

    public function services(): array
    {
        return [];
    }

    public function days(int $serviceId, int $locationId, ?int $staffId = null, int $count = 14): array
    {
        return [];
    }

    public function slots(int $serviceId, int $locationId, CarbonImmutable $day, ?int $staffId = null): array
    {
        return [];
    }
}
