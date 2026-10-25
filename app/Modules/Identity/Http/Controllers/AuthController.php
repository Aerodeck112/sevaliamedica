<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\Mfa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('identity::auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        $key = $this->throttleKey($request);
        $max = (int) config('sevalia.security.login_max_attempts', 5);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);
            Audit::log('auth.login_throttled', context: ['email_hash' => hash('sha256', Str::lower($data['email']))]);
            throw ValidationException::withMessages([
                'email' => 'Prea multe încercări. Reîncercați peste '.ceil($seconds / 60).' minute.',
            ]);
        }

        $user = User::where('email', Str::lower($data['email']))->first();
        // Comparăm parola și când utilizatorul nu există, pentru timp de răspuns comparabil.
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$'.str_repeat('a', 53));

        if (! $user || ! $valid || ! $user->is_active) {
            RateLimiter::hit($key, (int) config('sevalia.security.login_decay_seconds', 900));
            Audit::log('auth.login_failed', $user, ['motiv' => ! $user ? 'necunoscut' : (! $valid ? 'parola' : 'inactiv')], userId: $user?->id);
            throw ValidationException::withMessages(['email' => 'Datele de autentificare nu sunt corecte sau contul nu este activ.']);
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.started_at', time());
        $request->session()->put('mfa.passed', false);

        if ($user->hasMfaEnabled()) {
            return redirect()->route('mfa.challenge');
        }

        if (! $user->mfaRequired()) {
            return $this->completeLogin($request, $user, 'parola');
        }

        Audit::log('auth.login_password_ok', $user, ['mfa' => 'inrolare_necesara']);

        return redirect()->route('panou.mfa.setup');
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()?->hasMfaEnabled()) {
            return redirect()->route('login');
        }

        return view('identity::auth.mfa-challenge');
    }

    public function verifyChallenge(Request $request, Mfa $mfa): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->hasMfaEnabled(), 403);

        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $key = 'mfa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            Audit::log('auth.mfa_throttled', $user);
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('status', 'Prea multe coduri greșite. Autentificați-vă din nou mai târziu.');
        }

        $ok = $mfa->verify($user, $user->mfa_secret, $data['code']);
        $method = 'totp';
        if (! $ok && $mfa->useRecoveryCode($user, $data['code'])) {
            $ok = true;
            $method = 'cod_recuperare';
        }

        if (! $ok) {
            RateLimiter::hit($key, 900);
            Audit::log('auth.mfa_failed', $user);

            return back()->withErrors(['code' => 'Codul nu este valid.']);
        }

        RateLimiter::clear($key);

        return $this->completeLogin($request, $user, $method);
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($request->user()) {
            Audit::log('auth.logout', $request->user());
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'V-ați deconectat.');
    }

    public function showReauth(): View
    {
        return view('identity::auth.reauth');
    }

    public function reauth(Request $request, Mfa $mfa): RedirectResponse
    {
        $user = $request->user();
        $rules = ['password' => ['required', 'string']];
        if ($user->hasMfaEnabled()) {
            $rules['code'] = ['required', 'string', 'max:20'];
        }
        $data = $request->validate($rules);

        $key = 'reauth:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['password' => 'Prea multe încercări. Reîncercați mai târziu.']);
        }

        $ok = Hash::check($data['password'], $user->password)
            && (! $user->hasMfaEnabled() || $mfa->verify($user, $user->mfa_secret, $data['code']));

        if (! $ok) {
            RateLimiter::hit($key, 900);
            Audit::log('auth.reauth_failed', $user);

            return back()->withErrors(['password' => 'Parola sau codul nu sunt corecte.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('auth.reauth_at', time());
        Audit::log('auth.reauth', $user);

        return redirect()->intended(route('panou.dashboard'));
    }

    private function completeLogin(Request $request, User $user, string $method): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put('mfa.passed', true);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Audit::log('auth.login', $user, ['metoda' => $method]);

        return redirect()->intended(route('panou.dashboard'));
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.sha1(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }
}
