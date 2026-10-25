<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operațiile sensibile (actualizări, exporturi, unirea fișelor, administrarea conturilor) cer reconfirmarea
 * parolei și a codului MFA în ultimele N minute (sevalia.security.reauth_minutes).
 */
class RequireReauthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $at = (int) $request->session()->get('auth.reauth_at', 0);
        $window = (int) config('sevalia.security.reauth_minutes', 10) * 60;

        if ($at === 0 || time() - $at > $window) {
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            } else {
                $request->session()->put('url.intended', url()->previous());
            }

            return redirect()->route('panou.reauth');
        }

        return $next($request);
    }
}
