<?php

namespace App\Core\Contracts;

use Carbon\CarbonImmutable;

/**
 * Datele minime transmise de formularul public de programare către CRM.
 * Formularul public (modulul Site) construiește obiectul; modulul de programări îl preia.
 */
final class PublicAppointmentRequest
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $phone,
        public readonly ?string $email,
        /** telefon | email | sms */
        public readonly ?string $preferredContact,
        public readonly ?int $specialtyId,
        public readonly ?int $staffMemberId,
        public readonly ?int $serviceId,
        public readonly ?int $locationId,
        /** Interval dorit, dacă formularul permite alegerea unui interval concret. */
        public readonly ?CarbonImmutable $preferredStart,
        public readonly ?string $message,
        public readonly ?string $birthDate,
        public readonly CarbonImmutable $consentAt,
        public readonly string $sourceUrl,
        /** Hash al IP-ului (nu IP-ul în clar), pentru limitare și investigarea abuzurilor. */
        public readonly string $ipHash,
    ) {}
}
