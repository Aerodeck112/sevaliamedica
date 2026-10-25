@extends('layouts.panou')
@section('title', 'Coduri de recuperare')
@section('content')
    <x-panou.page-head title="Coduri de recuperare" sub="Păstrați aceste coduri într-un loc sigur, separat de telefon. Fiecare cod poate fi folosit o singură dată dacă nu aveți acces la aplicația de autentificare. Nu vor mai fi afișate." />
    <x-panou.panel style="max-width:560px">
        <ul class="code-list">
            @foreach ($codes as $code)<li>{{ $code }}</li>@endforeach
        </ul>
        <a class="btn btn--primary" href="{{ route('panou.dashboard') }}">Am salvat codurile</a>
    </x-panou.panel>
@endsection
