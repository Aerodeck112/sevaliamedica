<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Autentificare') — SEVALIA</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/panou.css'])
</head>
<body class="auth">
    <aside class="auth__side">
        <img src="/images/sevalia-logo-alb.png" alt="SEVALIA" width="140" height="34">
        <div>
            <h1>Panoul de lucru al clinicii</h1>
            <p>Programări, fișe, recuperare și raportări într-un singur loc. Accesul este personal și se înregistrează în jurnalul de audit.</p>
        </div>
        <p class="small">Probleme cu accesul? Contactați administratorul tehnic al clinicii.</p>
    </aside>
    <main class="auth__main">
        <div class="auth__card">
            @if (config('sevalia.stage') !== 'production')
                <p class="badge badge--warn">Mediu {{ config('sevalia.stage') === 'staging' ? 'staging — date de test' : 'de dezvoltare' }}</p>
            @endif
            @include('components.panou.flash')
            @yield('content')
        </div>
    </main>
</body>
</html>
