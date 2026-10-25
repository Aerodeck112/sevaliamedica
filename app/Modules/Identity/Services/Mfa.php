<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) cu protecție la reutilizarea aceluiași cod și coduri de recuperare de unică folosință.
 */
class Mfa
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl(config('app.name', 'SEVALIA'), $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * Verifică un cod TOTP. Un cod acceptat nu mai poate fi folosit din nou (fereastra ±1 interval).
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }

        $key = 'mfa.last_ts.'.$user->getKey();
        $last = Cache::get($key);
        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, $last, 1);
        if ($timestamp === false) {
            return false;
        }
        Cache::put($key, $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }

    /** Consumă un cod de recuperare, dacă există. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        $codes = $user->mfa_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $code)) {
                unset($codes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return array_map(
            fn () => implode('-', str_split(strtoupper(Str::random(12)), 4)),
            range(1, $count),
        );
    }
}
