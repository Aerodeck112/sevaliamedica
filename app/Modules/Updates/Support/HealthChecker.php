<?php

namespace App\Modules\Updates\Support;

/**
 * Verificările de funcționare ale unei versiuni după activare.
 */
interface HealthChecker
{
    /**
     * @return list<array{check: string, ok: bool, detail: string}>
     */
    public function check(string $releasePath, string $expectedVersion): array;
}
