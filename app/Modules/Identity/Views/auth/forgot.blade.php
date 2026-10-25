@extends('layouts.auth')
@section('title', 'Parolă uitată')
@section('content')
    <h2>Parolă uitată</h2>
    <p class="muted">Vă trimitem un link de resetare, valabil {{ config('auth.passwords.users.expire') }} de minute, o singură utilizare.</p>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <x-panou.field name="email" type="email" label="E-mail" required autocomplete="username" />
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Trimite linkul</button>
            <a href="{{ route('login') }}">Înapoi la autentificare</a>
        </div>
    </form>
@endsection
