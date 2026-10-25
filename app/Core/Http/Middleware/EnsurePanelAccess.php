<?php

namespace App\Core\Http\Middleware;

use App\Core\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Condițiile pentru orice rută din panou:
 *  - cont activ;
 *  - durata absolută a sesiunii nedepășită;
 *  - MFA trecut (sau înrolare MFA în curs, dacă e obligatoriu și încă neconfigurat);
 *  - răspunsuri fără cache partajat și marcate noindex.
 */
class EnsurePanelAccess
{
    /** Rute accesibile înainte de finalizarea MFA (doar pentru înrolare). */
    private const MFA_SETUP_ROUTES = ['panou.mfa.setup', 'panou.mfa.confirm', 'panou.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return $this->logout($request, 'Contul nu este activ.');
        }

        $started = (int) $request->session()->get('auth.started_at', 0);
        $max = (int) config('sevalia.security.absolute_session_minutes', 720) * 60;
        if ($started === 0 || time() - $started > $max) {
            return $this->logout($request, 'Sesiunea a expirat. Autentificați-vă din nou.');
        }

        if (! $request->session()->get('mfa.passed', false)) {
            if ($user->hasMfaEnabled()) {
                return redirect()->route('mfa.challenge');
            }
            if ($user->mfaRequired() && ! $request->routeIs(...self::MFA_SETUP_ROUTES)) {
                return redirect()->route('panou.mfa.setup')
                    ->with('status', 'Pentru acest cont, autentificarea în doi pași este obligatorie. Configurați-o pentru a continua.');
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function logout(Request $request, string $message): Response
    {
        if ($request->user()) {
            Audit::log('auth.session_terminated', $request->user(), ['motiv' => $message]);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
