<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function showRequest(): View
    {
        return view('identity::auth.forgot');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:190']]);

        $status = Password::sendResetLink(['email' => Str::lower($request->input('email')), 'is_active' => true]);
        Audit::log('auth.password_reset_requested', context: [
            'email_hash' => hash('sha256', Str::lower($request->input('email'))),
            'rezultat' => $status,
        ]);

        // Același mesaj indiferent dacă adresa există, ca să nu dezvăluim conturile.
        return back()->with('status', 'Dacă adresa aparține unui cont activ, veți primi un e-mail cu instrucțiuni.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('identity::auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', self::passwordRule()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();
                // Revocăm toate sesiunile existente ale contului.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                Audit::log('auth.password_reset', $user, userId: $user->id);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'Linkul de resetare nu este valid sau a expirat.']);
        }

        return redirect()->route('login')->with('status', 'Parola a fost schimbată. Vă puteți autentifica.');
    }

    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min((int) config('sevalia.security.password_min_length', 12))->letters()->numbers();
    }
}
