<?php

namespace App\Modules\Scheduling\Support;

/** Un motiv pentru care un interval nu poate fi rezervat, explicat pentru utilizator. */
final class Conflict
{
    public function __construct(
        /** staff | room | equipment | schedule | closure | input */
        public readonly string $type,
        public readonly string $message,
        public readonly ?int $appointmentId = null,
    ) {}

    public function toArray(): array
    {
        return ['type' => $this->type, 'message' => $this->message, 'appointment_id' => $this->appointmentId];
    }
}
