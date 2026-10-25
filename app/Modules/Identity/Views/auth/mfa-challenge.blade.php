@extends('layouts.auth')
@section('title', 'Cod de verificare')
@section('content')
    <h2>Cod de verificare</h2>
    <p class="muted">Introduceți codul de 6 cifre din aplicația de autentificare. Dacă nu aveți telefonul, folosiți un cod de recuperare.</p>
    <form method="post" action="{{ route('mfa.verify') }}">
        @csrf
        <x-panou.field name="code" label="Cod" required autocomplete="one-time-code" inputmode="numeric" autofocus />
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Verifică</button>
        </div>
    </form>
    <form method="post" action="{{ route('panou.logout') }}" style="margin-top:1rem">
        @csrf
        <button class="link-btn" type="submit">Anulează și ieși</button>
    </form>
@endsection
