<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($s) => (object) [
                'id' => $s->id,
                'ip' => $s->ip_address,
                'agent' => self::describeAgent((string) $s->user_agent),
                'last' => Carbon::createFromTimestamp($s->last_activity)->timezone(config('app.timezone')),
                'current' => $s->id === $request->session()->getId(),
            ]);

        return view('identity::profile.show', ['user' => $request->user(), 'sessions' => $sessions]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordResetController::passwordRule()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $request->input('password'), 'password_changed_at' => now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        Audit::log('auth.password_changed', $user);

        return back()->with('status', 'Parola a fost schimbată. Celelalte sesiuni au fost închise.');
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        $deleted = DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $session)
            ->where('id', '!=', $request->session()->getId())->delete();
        if ($deleted) {
            Audit::log('auth.session_revoked', $request->user());
        }

        return back()->with('status', $deleted ? 'Sesiunea a fost închisă.' : 'Sesiunea nu a fost găsită.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        Audit::log('auth.sessions_revoked_all', $request->user());

        return back()->with('status', 'Toate celelalte sesiuni au fost închise.');
    }

    public static function describeAgent(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser necunoscut',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return trim("$browser $os");
    }
}
