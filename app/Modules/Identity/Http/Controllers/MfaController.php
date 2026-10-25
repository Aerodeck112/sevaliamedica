<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\Mfa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MfaController extends Controller
{
    public function setup(Request $request, Mfa $mfa): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasMfaEnabled() && $request->session()->get('mfa.passed')) {
            return redirect()->route('panou.profile')->with('status', 'Autentificarea în doi pași este deja activă.');
        }

        $secret = $request->session()->get('mfa.pending_secret');
        if (! $secret) {
            $secret = $mfa->generateSecret();
            $request->session()->put('mfa.pending_secret', $secret);
        }

        return view('identity::profile.mfa-setup', [
            'qr' => $mfa->qrSvg($user, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    public function confirm(Request $request, Mfa $mfa): View|RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        $secret = $request->session()->get('mfa.pending_secret');

        if (! $secret || ! $mfa->verify($user, $secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'Codul nu corespunde. Verificați ora telefonului și reîncercați.']);
        }

        $codes = $mfa->generateRecoveryCodes();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => $codes,
        ])->save();
        $request->session()->forget('mfa.pending_secret');
        $request->session()->regenerate();
        $request->session()->put('mfa.passed', true);
        Audit::log('auth.mfa_enabled', $user);

        return view('identity::profile.recovery-codes', ['codes' => $codes]);
    }

    public function regenerateCodes(Request $request, Mfa $mfa): View
    {
        $user = $request->user();
        abort_unless($user->hasMfaEnabled(), 403);
        $codes = $mfa->generateRecoveryCodes();
        $user->forceFill(['mfa_recovery_codes' => $codes])->save();
        Audit::log('auth.mfa_recovery_regenerated', $user);

        return view('identity::profile.recovery-codes', ['codes' => $codes]);
    }
}
