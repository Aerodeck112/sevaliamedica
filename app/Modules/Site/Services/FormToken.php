<?php

namespace App\Modules\Site\Services;

use Illuminate\Support\Str;

/**
 * Token CSRF semnat, fără sesiune, pentru formularele publice: siteul public nu setează cookie de sesiune.
 * Tokenul leagă formularul, momentul afișării și un nonce, semnate HMAC cu cheia aplicației.
 * La trimitere se verifică semnătura, vârsta (minimum câteva secunde — roboții trimit instant; maximum 2 ore)
 * și antetul Origin/Referer (aceeași origine).
 */
final class FormToken
{
    public const FIELD = '_ft';

    public const HONEYPOT = 'website';

    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 7200;

    /** @return array<string, string> */
    public static function hiddenFields(string $form): array
    {
        return [self::FIELD => self::issue($form)];
    }

    public static function issue(string $form, ?int $time = null): string
    {
        $payload = $form.'.'.($time ?? time()).'.'.Str::random(12);

        return $payload.'.'.self::sign($payload);
    }

    /** Întoarce motivul respingerii sau null dacă tokenul e valid. */
    public static function verify(string $form, ?string $token, ?int $now = null): ?string
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 4) {
            return 'token';
        }
        [$f, $time, $nonce, $sig] = $parts;
        if (! hash_equals(self::sign("$f.$time.$nonce"), $sig) || $f !== $form) {
            return 'token';
        }
        $age = ($now ?? time()) - (int) $time;
        if ($age < self::MIN_SECONDS) {
            return 'prea_rapid';
        }
        if ($age > self::MAX_SECONDS) {
            return 'expirat';
        }

        return null;
    }

    private static function sign(string $payload): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', 'site-form|'.$payload, $key);
    }
}
