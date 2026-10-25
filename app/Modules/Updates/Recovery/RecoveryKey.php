<?php

namespace App\Modules\Updates\Recovery;

/**
 * Cheia de recuperare pentru public_html/recovery.php. Se stochează doar hash-ul (password_hash) în
 * shared/keys/recovery.hash; valoarea în clar se afișează o singură dată, la generare.
 */
final class RecoveryKey
{
    public static function path(string $keysDir): string
    {
        return rtrim($keysDir, '/').'/recovery.hash';
    }

    public static function exists(string $keysDir): bool
    {
        return is_file(self::path($keysDir));
    }

    public static function generate(string $keysDir): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $key = '';
        for ($i = 0; $i < 30; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $key = implode('-', str_split($key, 6));
        if (! is_dir($keysDir)) {
            mkdir($keysDir, 0700, true);
        }
        $old = umask(0077);
        try {
            file_put_contents(self::path($keysDir), password_hash($key, PASSWORD_DEFAULT)."\n", LOCK_EX);
        } finally {
            umask($old);
        }
        @chmod(self::path($keysDir), 0600);

        return $key;
    }
}
