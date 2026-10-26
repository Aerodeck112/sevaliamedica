<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Părerea dumneavoastră — SEVALIA</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/panou.css'])
</head>
<body class="panou" style="background:var(--mist)">
<main class="content" style="max-width:560px;margin:0 auto;padding-top:2.5rem">
    <p><img src="/images/sevalia-logo.webp" alt="SEVALIA" width="140" height="34"></p>
    @include('components.panou.flash')
    <x-panou.panel>
        @if (! $request)
            <h1 style="font-size:1.4rem">Linkul nu este valid</h1>
            <p>Verificați că ați copiat linkul complet.</p>
        @elseif ($done || $request->submitted_at)
            <h1 style="font-size:1.4rem">Vă mulțumim!</h1>
            <p>Răspunsul a fost înregistrat. Pentru orice problemă legată de tratament, vă rugăm să ne contactați telefonic{{ $request->appointment?->location?->phone ? ' la '.$request->appointment->location->phone : '' }}.</p>
        @elseif (! $request->isOpen())
            <h1 style="font-size:1.4rem">Linkul a expirat</h1>
            <p>Mulțumim pentru interes. Ne puteți contacta oricând telefonic.</p>
        @else
            <h1 style="font-size:1.4rem">Cum a fost vizita?</h1>
            <p class="muted">Vizita din {{ $request->appointment?->starts_at?->locale('ro')->isoFormat('D MMMM YYYY') }}, {{ $request->appointment?->location?->name }}.</p>
            <form method="post" action="{{ route('feedback.submit', $token) }}" class="stack">
                @csrf
                <fieldset>
                    <legend>Nota acordată (1 = nemulțumit, 5 = foarte mulțumit)</legend>
                    <div class="cluster">
                        @for ($i = 1; $i <= 5; $i++)
                            <label class="check"><input type="radio" name="rating" value="{{ $i }}" required @checked(old('rating') == $i)><span>{{ $i }}</span></label>
                        @endfor
                    </div>
                </fieldset>
                <x-panou.field name="comment" type="textarea" label="Comentariu (opțional)" hint="Vă rugăm să nu includeți informații medicale (diagnostice, tratamente, simptome). Pentru acestea, discutați direct cu medicul." />
                <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Lăsați gol<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <div><button class="btn btn--primary" type="submit">Trimite răspunsul</button></div>
            </form>
        @endif
    </x-panou.panel>
</main>
</body>
</html>
