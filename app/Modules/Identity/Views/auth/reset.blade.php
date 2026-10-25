@extends('layouts.auth')
@section('title', 'Parolă nouă')
@section('content')
    <h2>Setați o parolă nouă</h2>
    <p class="muted">Minimum {{ config('sevalia.security.password_min_length') }} caractere, cu litere și cifre. După schimbare, toate sesiunile deschise se închid.</p>
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-panou.field name="email" type="email" label="E-mail" :value="$email" required autocomplete="username" />
        <x-panou.field name="password" type="password" label="Parolă nouă" required autocomplete="new-password" />
        <x-panou.field name="password_confirmation" type="password" label="Repetați parola" required autocomplete="new-password" />
        <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează parola</button></div>
    </form>
@endsection
