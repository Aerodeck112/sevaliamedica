@extends('layouts.panou')
@section('title', 'Profilul meu')
@section('content')
    <x-panou.page-head title="Profilul meu" :sub="$user->email" />
    <div class="grid grid--2">
        <x-panou.panel title="Schimbă parola">
            <form method="post" action="{{ route('panou.profile.password') }}" class="stack">
                @csrf @method('put')
                <x-panou.field name="current_password" type="password" label="Parola actuală" required autocomplete="current-password" />
                <x-panou.field name="password" type="password" label="Parola nouă" required autocomplete="new-password" hint="Minimum {{ config('sevalia.security.password_min_length') }} caractere, cu litere și cifre." />
                <x-panou.field name="password_confirmation" type="password" label="Repetați parola nouă" required autocomplete="new-password" />
                <div><button class="btn btn--primary" type="submit">Schimbă parola</button></div>
            </form>
        </x-panou.panel>
        <x-panou.panel title="Autentificare în doi pași">
            @if ($user->hasMfaEnabled())
                <p><x-panou.badge tone="ok">Activă</x-panou.badge> din {{ $user->mfa_confirmed_at->format('d.m.Y') }}.</p>
                <p class="small muted">Coduri de recuperare rămase: {{ count($user->mfa_recovery_codes ?? []) }}.</p>
                <form method="post" action="{{ route('panou.mfa.codes') }}" data-confirm="Codurile vechi nu vor mai funcționa. Continuați?">
                    @csrf
                    <button class="btn" type="submit">Generează coduri noi</button>
                </form>
            @else
                <p><x-panou.badge tone="warn">Neconfigurată</x-panou.badge></p>
                <a class="btn btn--primary" href="{{ route('panou.mfa.setup') }}">Configurează</a>
            @endif
        </x-panou.panel>
        <x-panou.panel title="Sesiuni active" class="span-2" :flush="true">
            <x-slot:actions>
                <form method="post" action="{{ route('panou.profile.sessions.revoke-others') }}">
                    @csrf @method('delete')
                    <button class="btn btn--small" type="submit">Închide celelalte sesiuni</button>
                </form>
            </x-slot:actions>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Dispozitiv</th><th>IP</th><th>Ultima activitate</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($sessions as $s)
                        <tr>
                            <td>{{ $s->agent }} @if ($s->current)<x-panou.badge tone="accent">sesiunea curentă</x-panou.badge>@endif</td>
                            <td class="num">{{ $s->ip }}</td>
                            <td class="num">{{ $s->last->format('d.m.Y H:i') }}</td>
                            <td class="text-right">
                                @unless ($s->current)
                                    <form method="post" action="{{ route('panou.profile.sessions.revoke', $s->id) }}">
                                        @csrf @method('delete')
                                        <button class="btn btn--small btn--danger" type="submit">Închide</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
    </div>
@endsection
