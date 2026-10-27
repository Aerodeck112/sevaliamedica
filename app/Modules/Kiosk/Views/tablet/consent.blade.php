@extends('kiosk::tablet.layout')
@section('title', 'Consimțământ')
@section('idle-after', '300')
@section('content')
    <section class="k-card k-card--wide">
        <p class="k-muted">Pentru: <strong>{{ $session->patientShortName() }}</strong></p>
        <h1>{{ $session->consentVersion->template->name }}</h1>
        <div class="k-doc" tabindex="0">{!! nl2br(e($session->consentVersion->body)) !!}</div>
        <p class="k-muted small">Versiunea {{ $session->consentVersion->version }} a textului.</p>
        <form method="post" action="{{ route('kiosk.consent') }}" class="k-form" data-kiosk-sign>
            @csrf
            <label class="k-check"><input type="checkbox" name="agree" value="1" required> Am citit textul de mai sus și sunt de acord.</label>
            @error('agree')<p class="k-error">{{ $message }}</p>@enderror
            <label for="representative_name">Semnez ca reprezentant legal al pacientului (opțional) — numele meu:</label>
            <input id="representative_name" name="representative_name" autocomplete="off">
            <p class="k-label">Semnătura</p>
            <div class="k-sign">
                <canvas width="1200" height="360" aria-label="Spațiu pentru semnătură"></canvas>
                <button type="button" class="k-btn k-btn--ghost" data-sign-clear>Șterge semnătura</button>
            </div>
            <input type="hidden" name="signature" value="">
            @error('signature')<p class="k-error">{{ $message }}</p>@enderror
            <p class="k-muted small">Semnătura desenată pe tabletă este o semnătură electronică simplă, păstrată criptat în dosarul dumneavoastră împreună cu textul semnat.</p>
            <div class="k-actions">
                <button type="submit" name="action" value="semnez" class="k-btn">Semnez</button>
                <button type="submit" name="action" value="refuz" class="k-btn k-btn--ghost" formnovalidate data-confirm-refuse>Nu sunt de acord</button>
            </div>
        </form>
    </section>
@endsection
