@extends('layouts.auth')
@section('title', 'Autentificare')
@section('content')
    <h2>Autentificare</h2>
    <p class="muted">Folosiți contul personal primit de la clinică.</p>
    <form method="post" action="{{ route('login.attempt') }}" novalidate>
        @csrf
        <x-panou.field name="email" type="email" label="E-mail" required autocomplete="username" autofocus />
        <x-panou.field name="password" type="password" label="Parolă" required autocomplete="current-password" />
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Intră în cont</button>
            <a href="{{ route('password.request') }}">Am uitat parola</a>
        </div>
    </form>
@endsection
