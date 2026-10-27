<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tabletă') — SEVALIA</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/kiosk.css', 'resources/js/kiosk.js'])
</head>
<body class="kiosk" @hasSection('idle-after') data-idle-after="@yield('idle-after')" data-idle-url="{{ route('kiosk.home') }}" @endif>
    <header class="kiosk__head">
        <img src="/images/sevalia-logo.webp" alt="SEVALIA" width="140" height="34">
    </header>
    <main class="kiosk__main">
        @yield('content')
    </main>
</body>
</html>
