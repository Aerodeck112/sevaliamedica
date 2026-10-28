<?php

namespace App\Modules\Site\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-ul paginilor publice (HTML complet). Paginile publice nu conțin date personale; cache-ul se golește
 * la orice publicare și la modificarea datelor structurate afișate pe site (personal, locații, meniu, setări).
 */
final class PageCache
{
    private const VERSION_KEY = 'site.page-cache-version';

    public static function key(string $path): string
    {
        // Versiunea aplicației face parte din cheie: după o actualizare, paginile se generează din nou, cu stilurile
        // și scripturile noi (altfel rămâneau până la 12 ore cele vechi).
        return 'site.page.'.config('sevalia.version').'.'.self::version().'.'.sha1($path);
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    public static function enabled(): bool
    {
        $configured = config('sevalia.site.page_cache');

        return $configured === null ? ! app()->environment('local', 'testing') : (bool) $configured;
    }

    public static function get(string $path): ?array
    {
        return self::enabled() ? Cache::get(self::key($path)) : null;
    }

    public static function put(string $path, array $entry): void
    {
        if (self::enabled()) {
            Cache::put(self::key($path), $entry, now()->addHours(12));
        }
    }
}
