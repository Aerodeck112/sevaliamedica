<?php

namespace App\Core\Security;

use RuntimeException;

/**
 * Index „orb” pentru identificatori sensibili (ex. CNP): permite căutarea exactă și detectarea duplicatelor
 * fără a stoca valoarea în clar. Valoarea însăși se păstrează criptată separat.
 */
class BlindIndex
{
    public static function make(?string $value, string $purpose): ?string
    {
        $value = $value === null ? null : preg_replace('/\s+/', '', mb_strtoupper(trim($value)));
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $purpose.'|'.$value, self::key());
    }

    private static function key(): string
    {
        $key = (string) config('sevalia.security.blind_index_key');
        if ($key === '') {
            throw new RuntimeException('SEVALIA_BLIND_INDEX_KEY nu este configurată.');
        }

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }

    /** Afișare mascată: păstrează primele și ultimele caractere (ex. 1********123). */
    public static function mask(?string $value, int $keepStart = 1, int $keepEnd = 3): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $len = mb_strlen($value);
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat('•', $len);
        }

        return mb_substr($value, 0, $keepStart).str_repeat('•', $len - $keepStart - $keepEnd).mb_substr($value, -$keepEnd);
    }
}
