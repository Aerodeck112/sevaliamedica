@extends('layouts.panou')
@section('title', 'Autentificare în doi pași')
@section('content')
    <x-panou.page-head title="Autentificare în doi pași" sub="Scanați codul cu o aplicație de autentificare (de exemplu Microsoft Authenticator, Google Authenticator sau 2FAS), apoi introduceți codul de 6 cifre afișat." />
    <x-panou.panel style="max-width:640px">
        <div class="cluster" style="align-items:flex-start;gap:1.5rem">
            <div class="qr">{!! $qr !!}</div>
            <div class="stack" style="flex:1;min-width:220px">
                <p class="small muted">Dacă nu puteți scana, introduceți manual cheia:</p>
                <p><code style="font-size:1rem;word-break:break-all">{{ $secret }}</code></p>
                <form method="post" action="{{ route('panou.mfa.confirm') }}" class="stack">
                    @csrf
                    <x-panou.field name="code" label="Cod din aplicație" required autocomplete="one-time-code" inputmode="numeric" />
                    <button class="btn btn--primary" type="submit">Activează</button>
                </form>
            </div>
        </div>
    </x-panou.panel>
@endsection
