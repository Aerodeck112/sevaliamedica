@extends('kiosk::tablet.layout')
@section('title', 'Părerea dumneavoastră')
@section('idle-after', '180')
@section('content')
    <section class="k-card k-card--wide">
        <h1>Cum a fost la noi, {{ $session->patient->first_name }}?</h1>
        <dl class="k-facts">
            @if ($session->service)<dt>Serviciu</dt><dd>{{ $session->service->name }}</dd>@endif
            @if ($session->staffMember)<dt>{{ $session->staffMember->kind === 'medic' ? 'Medic' : 'Specialist' }}</dt><dd>{{ $session->staffMember->display_name }}</dd>@endif
        </dl>
        <form method="post" action="{{ route('kiosk.review') }}" class="k-form">
            @csrf
            <fieldset class="k-stars" data-kiosk-stars>
                <legend>Acordați o notă</legend>
                @for ($i = 5; $i >= 1; $i--)
                    <input type="radio" id="star{{ $i }}" name="rating" value="{{ $i }}" @checked(old('rating') == $i) required>
                    <label for="star{{ $i }}" title="{{ $i }} {{ $i === 1 ? 'stea' : 'stele' }}"><span class="sr-only">{{ $i }} {{ $i === 1 ? 'stea' : 'stele' }}</span>★</label>
                @endfor
            </fieldset>
            <p class="k-stars__text" data-stars-text aria-live="polite"></p>
            @error('rating')<p class="k-error">{{ $message }}</p>@enderror
            <label for="comment">Mai doriți să ne spuneți ceva? (opțional)</label>
            <textarea id="comment" name="comment" rows="4" maxlength="2000" placeholder="Ce v-a plăcut, ce putem îmbunătăți…">{{ old('comment') }}</textarea>
            <label class="k-check"><input type="checkbox" name="publish_consent" value="1"> Sunt de acord ca recenzia mea să fie publicată pe site (doar prenumele și inițiala numelui).</label>
            <div class="k-actions"><button type="submit" class="k-btn">Trimite</button></div>
        </form>
    </section>
@endsection
