<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $users = User::with('roles')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('identity::users.index', ['users' => $users, 'q' => $q]);
    }

    public function create(): View
    {
        return view('identity::users.form', ['user' => new User(['is_active' => true]), 'roles' => $this->roles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                // Parolă aleatorie; utilizatorul își setează parola prin linkul de resetare.
                'password' => Str::password(40),
                'is_active' => $data['is_active'] ?? false,
            ]);
            $this->assertCanAssign($data['roles'] ?? []);
            $user->syncRoles($data['roles'] ?? []);
            Audit::log('users.created', $user, ['roluri' => $data['roles'] ?? []]);

            return $user;
        });

        Password::sendResetLink(['email' => $user->email]);

        return redirect()->route('panou.users.index')
            ->with('status', "Contul pentru {$user->name} a fost creat. A fost trimis un e-mail pentru setarea parolei.");
    }

    public function edit(User $user): View
    {
        return view('identity::users.form', ['user' => $user, 'roles' => $this->roles()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if ($user->is($request->user()) && ! ($data['is_active'] ?? false)) {
            return back()->withErrors(['is_active' => 'Nu vă puteți dezactiva propriul cont.']);
        }

        DB::transaction(function () use ($data, $user) {
            $before = ['roluri' => $user->getRoleNames()->all(), 'activ' => $user->is_active];
            $user->update(['name' => $data['name'], 'email' => Str::lower($data['email']), 'is_active' => $data['is_active'] ?? false]);
            $this->assertCanAssign($data['roles'] ?? []);
            $user->syncRoles($data['roles'] ?? []);
            if (! $user->is_active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::log('users.updated', $user, ['inainte' => $before, 'dupa' => ['roluri' => $data['roles'] ?? [], 'activ' => $user->is_active]]);
        });

        return redirect()->route('panou.users.index')->with('status', 'Contul a fost actualizat.');
    }

    public function resetMfa(User $user): RedirectResponse
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::log('users.mfa_reset', $user);

        return back()->with('status', 'MFA a fost resetat. Utilizatorul îl va configura la următoarea autentificare.');
    }

    public function revokeSessions(User $user): RedirectResponse
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::log('users.sessions_revoked', $user);

        return back()->with('status', 'Toate sesiunile utilizatorului au fost închise.');
    }

    public function sendReset(User $user): RedirectResponse
    {
        Password::sendResetLink(['email' => $user->email]);
        Audit::log('users.password_reset_sent', $user);

        return back()->with('status', 'Linkul de resetare a parolei a fost trimis.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);
    }

    /** Doar administratorul tehnic poate acorda rolul de administrator tehnic. */
    private function assertCanAssign(array $roles): void
    {
        if (in_array(Roles::ADMIN_TEHNIC, $roles, true) && ! auth()->user()->hasRole(Roles::ADMIN_TEHNIC)) {
            abort(403, 'Nu puteți acorda rolul de administrator tehnic.');
        }
    }

    private function roles()
    {
        return Role::orderBy('name')->get()->map(fn ($r) => (object) ['name' => $r->name, 'label' => Roles::label($r->name)]);
    }
}
