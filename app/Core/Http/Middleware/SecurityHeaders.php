<?php

namespace App\Core\Http\Middleware;

use App\Core\Security\Csp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Antete de securitate pentru toate răspunsurile web, inclusiv Content-Security-Policy cu nonce.
 * Scripturile inline sunt permise numai cu nonce; atributele style sunt permise (conținutul importat le folosește).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);
        app()->instance('csp-nonce', $nonce);
        Csp::reset();

        $response = $next($request);

        // Sursele de bază din configurare + cele înregistrate dinamic pentru pagina curentă (App\Core\Security\Csp).
        $frames = implode(' ', [...config('sevalia.csp.frame_src', []), ...Csp::extra('frame-src')]);
        $connect = implode(' ', [...config('sevalia.csp.connect_src', []), ...Csp::extra('connect-src')]);
        $img = implode(' ', [...config('sevalia.csp.img_src', []), ...Csp::extra('img-src')]);
        $scripts = implode(' ', Csp::extra('script-src'));

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$scripts}",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: {$img}",
            "font-src 'self' data:",
            "connect-src 'self' {$connect}",
            "frame-src 'self' {$frames}",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ];
        if ($request->isSecure()) {
            $csp[] = 'upgrade-insecure-requests';
        }

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', implode('; ', $csp), false);
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure() && app()->environment('production')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
