<?php

namespace App\Core\Contracts;

/**
 * Rezultatul preluării unei solicitări publice.
 * confirmed = true numai dacă un interval concret a fost rezervat conform regulilor; altfel mesajul către pacient
 * confirmă doar înregistrarea solicitării, fără a promite un interval.
 */
final class IntakeResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly bool $confirmed,
        public readonly string $publicMessage,
        public readonly ?string $reference = null,
    ) {}
}
