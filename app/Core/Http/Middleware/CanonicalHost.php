<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domeniul are o singură adresă: cea din APP_URL (ex. www.sevalia.ro). Varianta cu sau fără „www.” se
 * redirecționează permanent către ea.
 *
 * Fără redirecționare, o pagină publică pusă în cache la o cerere pe sevalia.ro trimitea vizitatorilor de pe
 * www.sevalia.ro stilurile de pe celălalt domeniu, blocate de Content-Security-Policy („'self'”): pagina apărea fără
 * design, doar uneori (în funcție de adresa primului vizitator). Alte domenii (ex. un alias de test) nu sunt atinse.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = strtolower($request->getHost());
        if ($canonical !== '' && $host !== $canonical && self::wwwVariant($host, $canonical)) {
            $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: $request->getScheme();
            $port = parse_url((string) config('app.url'), PHP_URL_PORT);
            $target = $scheme.'://'.$canonical.($port ? ':'.$port : '').$request->getRequestUri();

            return redirect()->away($target, $request->isMethodSafe() ? 301 : 308);
        }

        return $next($request);
    }

    private static function wwwVariant(string $host, string $canonical): bool
    {
        return $host === 'www.'.$canonical || 'www.'.$host === $canonical;
    }
}
