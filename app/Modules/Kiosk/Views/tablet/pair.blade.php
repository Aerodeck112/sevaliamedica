@extends('kiosk::tablet.layout')
@section('title', 'Asociere tabletă')
@section('content')
    <section class="k-card">
        <h1>Asociați tableta</h1>
        <p>În panou: <strong>Administrare → Tablete recepție → Tabletă nouă</strong>. Introduceți codul de asociere afișat acolo (valabil 30 de minute).</p>
        <form method="post" action="{{ route('kiosk.pair.store') }}" class="k-form">
            @csrf
            <label for="pairing_code">Cod de asociere</label>
            <input id="pairing_code" name="pairing_code" autocomplete="off" autocapitalize="characters" required placeholder="XXXX-XXXX">
            @error('pairing_code')<p class="k-error">{{ $message }}</p>@enderror
            <button type="submit" class="k-btn">Asociază</button>
        </form>
    </section>
@endsection
