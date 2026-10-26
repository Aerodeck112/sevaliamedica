<?php

namespace App\Core\Security;

/**
 * Surse CSP suplimentare pentru cererea curentă (ex. o etichetă de măsurare activă doar pe anumite pagini).
 * Middleware-ul SecurityHeaders le adaugă la politica de bază și le golește la începutul fiecărei cereri.
 */
final class Csp
{
    /** @var array<string, list<string>> */
    private static array $extra = [];

    public static function allow(string $directive, array $sources): void
    {
        foreach ($sources as $source) {
            if (preg_match('#^https://[a-z0-9*.-]+(/[^\s;]*)?$#i', $source)) {
                self::$extra[$directive][] = $source;
            }
        }
    }

    /** @return list<string> */
    public static function extra(string $directive): array
    {
        return array_values(array_unique(self::$extra[$directive] ?? []));
    }

    public static function reset(): void
    {
        self::$extra = [];
    }
}
