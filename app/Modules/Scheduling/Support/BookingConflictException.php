<?php

namespace App\Modules\Scheduling\Support;

use RuntimeException;

class BookingConflictException extends RuntimeException
{
    /** @param  list<Conflict>  $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(implode(' ', array_map(fn (Conflict $c) => $c->message, $conflicts)));
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(fn (Conflict $c) => $c->message, $this->conflicts);
    }
}
