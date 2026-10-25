@extends('layouts.panou')
@section('title', 'Confirmați identitatea')
@section('content')
    <x-panou.page-head title="Confirmați identitatea" sub="Operația cerută este sensibilă. Confirmați parola{{ auth()->user()->hasMfaEnabled() ? ' și codul de verificare' : '' }}; confirmarea rămâne valabilă {{ config('sevalia.security.reauth_minutes') }} minute." />
    <x-panou.panel style="max-width:520px">
        <form method="post" action="{{ route('panou.reauth.confirm') }}" class="stack">
            @csrf
            <x-panou.field name="password" type="password" label="Parolă" required autocomplete="current-password" autofocus />
            @if (auth()->user()->hasMfaEnabled())
                <x-panou.field name="code" label="Cod de verificare" required autocomplete="one-time-code" inputmode="numeric" />
            @endif
            <div class="form-actions"><button class="btn btn--primary" type="submit">Confirmă</button></div>
        </form>
    </x-panou.panel>
@endsection
