@extends('kiosk::tablet.layout')
@section('title', 'Mulțumim')
@section('idle-after', $kind === 'recenzie' && $googleReviewUrl ? '45' : '8')
@section('content')
    <section class="k-card k-card--center">
        <div class="k-check-anim" aria-hidden="true"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M14 27l8 8 16-17"/></svg></div>
        @if ($kind === 'refuz')
            <h1>Am înregistrat răspunsul dumneavoastră</h1>
            <p class="k-lead">Vă rugăm să vă adresați recepției pentru detalii.</p>
        @elseif ($kind === 'recenzie')
            <h1>Mulțumim pentru părere!</h1>
            <p class="k-lead">Ne ajută să fim mai buni în fiecare zi.</p>
            @if ($googleReviewUrl)
                <p>Dacă doriți, ne puteți lăsa o recenzie și pe Google — scanați codul cu telefonul:</p>
                <img class="k-qr" src="data:image/svg+xml;base64,{{ base64_encode((new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(260, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd)))->writeString($googleReviewUrl)) }}" alt="Cod QR pentru recenzie Google" width="260" height="260">
            @endif
        @else
            <h1>Mulțumim!</h1>
            <p class="k-lead">Consimțământul a fost semnat și salvat în dosarul dumneavoastră.</p>
        @endif
        <a class="k-btn k-btn--ghost" href="{{ route('kiosk.home') }}">Gata</a>
    </section>
@endsection
