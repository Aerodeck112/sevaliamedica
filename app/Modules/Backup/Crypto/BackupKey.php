<?php

namespace App\Modules\Backup\Crypto;

use RuntimeException;

/**
 * Cheia de criptare a backupurilor (32 de octeți), separată de APP_KEY, păstrată în shared/keys/backup.key
 * cu permisiuni 0600. O copie trebuie păstrată offline: fără ea backupurile nu pot fi restaurate.
 */
final class BackupKey
{
    private function __construct(public readonly string $bytes) {}

    public static function path(string $keysDir): string
    {
        return rtrim($keysDir, '/').'/backup.key';
    }

    public static function exists(string $keysDir): bool
    {
        return is_file(self::path($keysDir));
    }

    public static function load(string $keysDir): self
    {
        $file = self::path($keysDir);
        if (! is_file($file)) {
            throw new RuntimeException('Cheia de backup lipsește ('.$file.'). Rulați instalarea sau restaurați cheia din copia offline.');
        }
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Cheia de backup are un format invalid.');
        }

        return new self($raw);
    }

    /** Generează o cheie nouă; refuză să suprascrie una existentă (backupurile vechi ar deveni ilizibile). */
    public static function generate(string $keysDir): self
    {
        if (self::exists($keysDir)) {
            throw new RuntimeException('Cheia de backup există deja; nu se suprascrie.');
        }
        if (! is_dir($keysDir)) {
            mkdir($keysDir, 0700, true);
        }
        $raw = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $file = self::path($keysDir);
        $old = umask(0077);
        try {
            file_put_contents($file, base64_encode($raw)."\n", LOCK_EX);
        } finally {
            umask($old);
        }
        @chmod($file, 0600);

        return new self($raw);
    }

    /** Identificator scurt (nu dezvăluie cheia) scris în antetul fiecărui backup. */
    public function id(): string
    {
        return substr(hash('sha256', 'sevalia-backup-key|'.$this->bytes, true), 0, 8);
    }

    public function fingerprint(): string
    {
        return bin2hex($this->id());
    }
}
