<?php

namespace App\Modules\Notifications\Channels;

final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $simulated,
        public readonly bool $retryable,
        public readonly ?string $providerId,
        public readonly ?string $error,
    ) {}

    public static function sent(?string $providerId = null): self
    {
        return new self(true, false, false, $providerId, null);
    }

    public static function simulated(): self
    {
        return new self(true, true, false, null, null);
    }

    /** Eroare temporară (rețea, 5xx, limitare): se reîncearcă cu întârziere. */
    public static function temporary(string $error): self
    {
        return new self(false, false, true, null, self::clean($error));
    }

    /** Eroare permanentă (configurare greșită, număr invalid, 4xx): nu se reîncearcă automat. */
    public static function permanent(string $error): self
    {
        return new self(false, false, false, null, self::clean($error));
    }

    /** Elimină din mesajele de eroare numerele de telefon, adresele de e-mail, linkurile și tokenurile. */
    public static function clean(string $error): string
    {
        $error = preg_replace('~https?://\S+~i', '[link]', $error);
        $error = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[e-mail]', $error);
        $error = preg_replace('/\+?\d[\d\s().-]{5,}\d/', '[număr]', $error);
        $error = preg_replace('/\b[A-Za-z0-9_\-]{24,}\b/', '[token]', $error);

        return mb_substr(trim((string) $error), 0, 300);
    }
}
