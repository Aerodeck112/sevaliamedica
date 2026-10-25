<?php

namespace App\Modules\Updates\Support;

/**
 * disk_free_space() limitat opțional de o cotă declarată (SEVALIA_DISK_FREE_CAP), pentru hostingurile
 * unde spațiul real disponibil este cota contului cPanel, nu discul serverului.
 */
class NativeDiskSpace implements DiskSpace
{
    public function freeBytes(string $path): ?int
    {
        while ($path !== '' && $path !== '/' && ! file_exists($path)) {
            $path = dirname($path);
        }
        $free = @disk_free_space($path ?: '/');
        if ($free === false) {
            return null;
        }
        $quota = (int) config('updates.disk_free_cap', 0);

        return $quota > 0 ? (int) min($free, $quota) : (int) $free;
    }
}
