@extends('kiosk::tablet.layout')
@section('title', 'Bine ați venit')
@section('content')
    <section class="k-card k-card--center" data-kiosk-poll="{{ route('kiosk.poll') }}">
        <h1>Bine ați venit la {{ $clinic }}</h1>
        <p class="k-lead">Introduceți codul de 6 cifre primit de la recepție.</p>
        <form method="post" action="{{ route('kiosk.code') }}" class="k-pin" data-kiosk-pin>
            @csrf
            <input type="hidden" name="code" value="">
            <div class="k-pin__dots" aria-live="polite" aria-label="Cifre introduse"><span></span><span></span><span></span><span></span><span></span><span></span></div>
            @error('code')<p class="k-error" role="alert">{{ $message }}</p>@enderror
            <div class="k-pin__pad">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d)
                    <button type="button" class="k-key" data-key="{{ $d }}">{{ $d }}</button>
                @endforeach
                <button type="button" class="k-key k-key--muted" data-key="del" aria-label="Șterge ultima cifră">⌫</button>
                <button type="button" class="k-key" data-key="0">0</button>
                <button type="submit" class="k-key k-key--ok" aria-label="Continuă">OK</button>
            </div>
            <noscript><input name="code" inputmode="numeric" maxlength="6" class="k-noscript"><button class="k-btn">Continuă</button></noscript>
        </form>
        <p class="k-muted">Tableta: {{ $device->name }}</p>
    </section>
@endsection
