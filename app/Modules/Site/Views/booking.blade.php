@extends('site::layout')
{{-- Programare online: fiecare pas este un link; funcționează fără JavaScript și fără sesiune. --}}
@php
    $svc = $state['service'];
    $link = fn (array $q) => '/programare-online?'.http_build_query(array_filter($q, fn ($v) => $v !== null && $v !== ''));
    $base = ['serviciu' => $svc['id'] ?? null, 'locatie' => $state['location'], 'specialist' => $state['staff']];
    $grouped = collect($services)->groupBy(fn ($s) => $s['specialty'] ?? 'Servicii');
    $err = $formErrors;
    $o = fn (string $k, $d = null) => $old[$k] ?? $d;
@endphp
@section('main')
<div id="wrap">
    <section class="sv-booking">
        <div class="container">
            <header class="sv-booking__head">
                <p class="sv-booking__eyebrow">Programare online</p>
                <h1 class="sv-booking__title">Alegeți serviciul, ziua și ora</h1>
                <p class="sv-booking__lead">Vedeți intervalele libere din calendarul clinicii. Durează sub un minut.@if ($phone) Preferați telefonul? <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a>@endif</p>
            </header>

            @if (! $available)
                <div class="sv-booking__card sv-booking__empty">
                    <h2>Programarea online va fi disponibilă în curând</h2>
                    <p>Până atunci, ne puteți scrie din <a href="/contactus">formularul de contact</a>@if ($phone) sau ne puteți suna la <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a>@endif.</p>
                </div>
            @else
                <ol class="sv-booking__steps">
                    {{-- 1. Serviciul --}}
                    <li class="sv-booking__step {{ $svc ? 'is-done' : 'is-current' }}" id="pas-serviciu">
                        <h2 class="sv-booking__h"><span class="sv-booking__n">1</span> Serviciul</h2>
                        @if ($svc)
                            <p class="sv-booking__chosen"><strong>{{ $svc['name'] }}</strong> · {{ $svc['duration'] }} min <a href="/programare-online">schimbă</a></p>
                        @else
                            @foreach ($grouped as $group => $items)
                                <h3 class="sv-booking__group">{{ $group }}</h3>
                                <div class="sv-booking__options">
                                    @foreach ($items as $s)
                                        <a class="sv-booking__option" href="{{ $link(['serviciu' => $s['id']]) }}#pas-zi">
                                            <span class="sv-booking__option-name">{{ $s['name'] }}</span>
                                            <span class="sv-booking__option-meta">{{ $s['duration'] }} min · {{ implode(', ', $s['locations']) }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endforeach
                        @endif
                    </li>

                    @if ($svc)
                        {{-- 2. Locația și specialistul --}}
                        <li class="sv-booking__step {{ $state['location'] ? 'is-done' : 'is-current' }}" id="pas-locatie">
                            <h2 class="sv-booking__h"><span class="sv-booking__n">2</span> Locația și specialistul</h2>
                            <div class="sv-booking__chips" role="group" aria-label="Locația">
                                @foreach ($svc['locations'] as $id => $name)
                                    <a class="sv-booking__chip" href="{{ $link(['serviciu' => $svc['id'], 'locatie' => $id, 'specialist' => $state['staff']]) }}#pas-zi" @if ($state['location'] === $id) aria-current="true" @endif>{{ $name }}</a>
                                @endforeach
                            </div>
                            @if ($state['location'] && count($svc['staff']) > 0)
                                <div class="sv-booking__chips" role="group" aria-label="Specialistul">
                                    <a class="sv-booking__chip" href="{{ $link(['serviciu' => $svc['id'], 'locatie' => $state['location']]) }}#pas-zi" @if (! $state['staff']) aria-current="true" @endif>Oricare specialist disponibil</a>
                                    @foreach ($svc['staff'] as $id => $name)
                                        <a class="sv-booking__chip" href="{{ $link(['serviciu' => $svc['id'], 'locatie' => $state['location'], 'specialist' => $id]) }}#pas-zi" @if ($state['staff'] === $id) aria-current="true" @endif>{{ $name }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endif

                    @if ($svc && $state['location'])
                        {{-- 3. Ziua și ora --}}
                        <li class="sv-booking__step {{ $slot ? 'is-done' : 'is-current' }}" id="pas-zi">
                            <h2 class="sv-booking__h"><span class="sv-booking__n">3</span> Ziua și ora</h2>
                            @if (! $days)
                                <p class="sv-booking__none">Nu mai sunt intervale libere online în perioada următoare.@if ($phone) Sunați-ne la <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a> — de multe ori găsim o soluție.@endif</p>
                            @else
                                <div class="sv-booking__days" role="group" aria-label="Ziua">
                                    @foreach ($days as $d)
                                        @php $date = \Carbon\CarbonImmutable::parse($d)->locale('ro'); @endphp
                                        <a class="sv-booking__day" href="{{ $link($base + ['zi' => $d]) }}#pas-zi" @if ($state['day'] === $d) aria-current="date" @endif>
                                            <span class="sv-booking__dow">{{ $date->isToday() ? 'azi' : ($date->isTomorrow() ? 'mâine' : $date->isoFormat('ddd')) }}</span>
                                            <span class="sv-booking__dnum">{{ $date->format('j') }}</span>
                                            <span class="sv-booking__mon">{{ $date->isoFormat('MMM') }}</span>
                                        </a>
                                    @endforeach
                                </div>
                                @if ($err->has('start'))<p class="sv-booking__error" role="alert">{{ $err->first('start') }}</p>@endif
                                <div class="sv-booking__times" role="group" aria-label="Ora">
                                    @foreach ($slots as $s)
                                        <a class="sv-booking__time" href="{{ $link($base + ['zi' => $state['day'], 'ora' => $s['start']->format('H:i'), 'specialist' => $state['staff']]) }}#pas-date"
                                            @if ($slot && $slot['start']->equalTo($s['start'])) aria-current="true" @endif>{{ $s['start']->format('H:i') }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endif

                    @if ($slot)
                        {{-- 4. Datele de contact --}}
                        <li class="sv-booking__step is-current" id="pas-date">
                            <h2 class="sv-booking__h"><span class="sv-booking__n">4</span> Datele dumneavoastră</h2>
                            <div class="sv-booking__summary">
                                <strong>{{ $svc['name'] }}</strong><br>
                                {{ ucfirst($slot['start']->locale('ro')->isoFormat('dddd, D MMMM YYYY')) }}, ora {{ $slot['start']->format('H:i') }}
                                · {{ $svc['locations'][$state['location']] }}@if ($slot['staff']) · {{ $slot['staff'] }}@endif
                            </div>
                            @if ($err->has('form'))<p class="sv-booking__error" role="alert">{{ $err->first('form') }}</p>@endif
                            <form method="post" action="/programare-online" class="sv-booking__form" novalidate>
                                <input type="hidden" name="{{ \App\Modules\Site\Services\FormToken::FIELD }}" value="{{ $token }}">
                                <input type="hidden" name="service" value="{{ $svc['id'] }}">
                                <input type="hidden" name="location" value="{{ $state['location'] }}">
                                <input type="hidden" name="staff" value="{{ $state['staff'] }}">
                                <input type="hidden" name="start" value="{{ $slot['start']->toIso8601String() }}">
                                <div class="sv-booking__hp" aria-hidden="true"><label>Site web <input name="{{ \App\Modules\Site\Services\FormToken::HONEYPOT }}" tabindex="-1" autocomplete="off"></label></div>
                                <div class="sv-booking__fields">
                                    @foreach ([['name', 'Nume și prenume', 'text', 'name', true], ['phone', 'Telefon', 'tel', 'tel', true], ['email', 'E-mail (opțional)', 'email', 'email', false]] as [$f, $label, $type, $ac, $req])
                                        <div class="sv-booking__field">
                                            <label for="b_{{ $f }}">{{ $label }}</label>
                                            <input id="b_{{ $f }}" name="{{ $f }}" type="{{ $type }}" autocomplete="{{ $ac }}" value="{{ $o($f) }}" @if ($req) required @endif
                                                @if ($err->has($f)) aria-invalid="true" aria-describedby="b_{{ $f }}_e" @endif @if ($f === 'phone') inputmode="tel" @endif>
                                            @if ($err->has($f))<p class="sv-booking__error" id="b_{{ $f }}_e">{{ $err->first($f) }}</p>@endif
                                        </div>
                                    @endforeach
                                </div>
                                <fieldset class="sv-booking__contact">
                                    <legend>Cum preferați să vă contactăm?</legend>
                                    @foreach (['telefon' => 'Telefon', 'sms' => 'SMS', 'email' => 'E-mail'] as $v => $label)
                                        <label><input type="radio" name="contact" value="{{ $v }}" @checked($o('contact', 'telefon') === $v)> {{ $label }}</label>
                                    @endforeach
                                </fieldset>
                                <div class="sv-booking__field">
                                    <label for="b_message">Mesaj (opțional)</label>
                                    <textarea id="b_message" name="message" rows="3" maxlength="1000" placeholder="Ex. am o trimitere, prefer un anumit specialist…">{{ $o('message') }}</textarea>
                                    <p class="sv-booking__hint">Nu este nevoie să descrieți aici detalii medicale; le discutați la consultație.</p>
                                </div>
                                <label class="sv-booking__consent">
                                    <input type="checkbox" name="consent" value="1" required @checked($o('consent'))>
                                    <span>Sunt de acord cu prelucrarea datelor pentru programare, conform <a href="/politica-de-confidentialitate" target="_blank" rel="noopener">politicii de confidențialitate</a>.</span>
                                </label>
                                @if ($err->has('consent'))<p class="sv-booking__error">{{ $err->first('consent') }}</p>@endif
                                <button type="submit" class="btn btn-primary sv-booking__submit">Trimite programarea</button>
                                <p class="sv-booking__hint">Veți vedea imediat dacă intervalul este confirmat sau dacă vă contactează recepția pentru confirmare.</p>
                            </form>
                        </li>
                    @endif
                </ol>
            @endif
        </div>
    </section>
</div>
@endsection
