<?php

namespace App\Modules\Updates\Support;

/**
 * Spațiul liber pe disc. Interfață injectabilă: pe unele hostinguri disk_free_space() raportează discul
 * fizic, nu cota contului, iar în teste trebuie simulat spațiul insuficient.
 */
interface DiskSpace
{
    /** Octeți liberi utilizabili la calea dată, sau null dacă nu se poate determina. */
    public function freeBytes(string $path): ?int;
}
