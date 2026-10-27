<?php

namespace App\Modules\Kiosk\Http\Middleware;

use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Services\KioskService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifică tableta după tokenul de dispozitiv (cookie criptat, păstrat în baza de date doar ca hash).
 * Fără tabletă asociată, cererea ajunge la ecranul de asociere.
 */
class KioskDeviceAuth
{
    public const COOKIE = 'sevalia_tableta';

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->cookie(self::COOKIE);
        $device = $token !== '' ? KioskDevice::where('token_hash', KioskService::hashToken($token))->whereNull('revoked_at')->first() : null;
        if (! $device) {
            return redirect()->route('kiosk.pair');
        }
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        $request->attributes->set('kiosk_device', $device);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
