@extends('layouts.panou')
@section('title', $user->exists ? $user->name : 'Cont nou')
@section('content')
    <x-panou.page-head :title="$user->exists ? $user->name : 'Cont nou'" :crumbs="['Utilizatori' => route('panou.users.index'), ($user->exists ? 'Editare' : 'Cont nou') => null]"
        :sub="$user->exists ? null : 'Persoana primește un e-mail pentru a-și seta parola. La prima autentificare configurează autentificarea în doi pași.'" />
    <div class="grid grid--aside">
        <x-panou.panel>
            <form method="post" action="{{ $user->exists ? route('panou.users.update', $user) : route('panou.users.store') }}">
                @csrf
                @if ($user->exists) @method('put') @endif
                <div class="form-grid">
                    <x-panou.field name="name" label="Nume complet" :value="$user->name" required />
                    <x-panou.field name="email" type="email" label="E-mail" :value="$user->email" required />
                    <fieldset class="field--full">
                        <legend>Roluri</legend>
                        <div class="grid grid--3" style="gap:.5rem">
                            @foreach ($roles as $role)
                                <label class="check">
                                    <input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked(in_array($role->name, old('roles', $user->exists ? $user->getRoleNames()->all() : []), true))>
                                    <span>{{ $role->label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-panou.field name="is_active" type="checkbox" label="Cont activ" :value="$user->is_active" full />
                </div>
                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">{{ $user->exists ? 'Salvează modificările' : 'Creează contul' }}</button>
                    <a class="btn btn--ghost" href="{{ route('panou.users.index') }}">Renunță</a>
                </div>
            </form>
        </x-panou.panel>
        @if ($user->exists)
            <x-panou.panel title="Securitate">
                <dl class="facts">
                    <dt>MFA</dt><dd>{{ $user->hasMfaEnabled() ? 'activ' : 'neconfigurat' }}</dd>
                    <dt>Ultima autentificare</dt><dd>{{ $user->last_login_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                    <dt>Parolă schimbată</dt><dd>{{ $user->password_changed_at?->format('d.m.Y') ?? '—' }}</dd>
                </dl>
                <hr class="sep">
                <div class="stack">
                    <form method="post" action="{{ route('panou.users.send-reset', $user) }}">@csrf<button class="btn" type="submit">Trimite link de resetare a parolei</button></form>
                    <form method="post" action="{{ route('panou.users.revoke-sessions', $user) }}" data-confirm="Închideți toate sesiunile acestui utilizator?">@csrf<button class="btn" type="submit">Închide toate sesiunile</button></form>
                    <form method="post" action="{{ route('panou.users.reset-mfa', $user) }}" data-confirm="Utilizatorul va trebui să configureze din nou MFA. Continuați?">@csrf<button class="btn btn--danger" type="submit">Resetează MFA</button></form>
                </div>
            </x-panou.panel>
        @endif
    </div>
@endsection
