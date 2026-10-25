{{--
    Layoutul panoului. Utilizare în pagini:
        @extends('layouts.panou')
        @section('title', 'Titlul paginii')
        @section('wide', '1')            (opțional: conținut pe toată lățimea, ex. calendar)
        @section('content') ... @endsection
--}}
@php
    $registry = app(\App\Core\Modules\ModuleRegistry::class);
    $nav = $registry->navigationFor(auth()->user());
    $stage = config('sevalia.stage');
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim(View::yieldContent('title')) ?: 'Panou' }} — SEVALIA</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/panou.css', 'resources/js/panou.js'])
    @stack('head')
</head>
<body class="panou">
<a class="skip-link" href="#continut">Sari la conținut</a>
<div class="shell" data-nav-open="false">
    <aside class="sidebar" aria-label="Meniu principal">
        <a class="sidebar__brand" href="{{ route('panou.dashboard') }}">
            <img src="/images/sevalia-logo-alb.png" alt="SEVALIA" width="115" height="28">
        </a>
        @if ($stage !== 'production')
            <div class="sidebar__stage" role="note">Mediu: {{ $stage === 'staging' ? 'staging — date de test' : 'dezvoltare' }}</div>
        @endif
        <nav>
            @foreach ($nav as $group => $items)
                <div class="nav-group">
                    @if ($group !== 'Activitate' || count($nav) > 1)<p class="nav-group__title">{{ $group }}</p>@endif
                    <ul class="nav-list">
                        @foreach ($items as $item)
                            <li>
                                <a class="nav-link" href="{{ route($item->route) }}" @if (request()->routeIs($item->pattern())) aria-current="page" @endif>
                                    <x-panou.icon :name="$item->icon" />{{ $item->label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
    </aside>
    <div class="main">
        <header class="topbar">
            <button type="button" class="btn btn--ghost topbar__menu-btn" data-nav-toggle aria-expanded="false" aria-label="Deschide meniul">
                <x-panou.icon name="menu" />
            </button>
            @hasSection('search')
                @yield('search')
            @endif
            <div class="topbar__user">
                <a href="{{ route('panou.profile') }}" class="cluster" style="text-decoration:none;color:inherit">
                    <x-panou.icon name="user" />
                    <span class="who"><strong>{{ auth()->user()->name }}</strong></span>
                </a>
                <form method="post" action="{{ route('panou.logout') }}">
                    @csrf
                    <button class="btn btn--small" type="submit"><x-panou.icon name="logout" />Ieșire</button>
                </form>
            </div>
        </header>
        <main id="continut" class="content {{ View::hasSection('wide') ? 'content--wide' : '' }}" tabindex="-1">
            <x-panou.flash />
            @yield('content')
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
