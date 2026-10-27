{{-- Harta corpului de pe prima pagină: navigare orientativă spre paginile de terapii (nu recomandare medicală). --}}
@php
    $hotspots = ['atm' => [79, 50], 'gat_umeri' => [140, 100], 'coloana' => [100, 175], 'pelvin' => [100, 250], 'genunchi' => [120, 360], 'articulatii' => [42, 196]];
    $first = array_key_first($regions);
@endphp
<section class="sv-bodymap" data-bodymap data-active="{{ $first }}" aria-labelledby="sv-bodymap-title">
    <div class="container">
        <div class="sv-bodymap__grid">
            <div class="sv-bodymap__figure sv-anatomy">
                @include('site::partials.anatomy-figure', ['focus' => array_keys($regions)])
                @foreach ($regions as $key => $region)
                    @isset($hotspots[$key])
                        <button type="button" class="sv-bodymap__hotspot" data-zone="{{ $key }}" tabindex="-1" aria-hidden="true"
                            style="left: {{ round($hotspots[$key][0] / 2, 2) }}%; top: {{ round($hotspots[$key][1] / 4.44, 2) }}%"></button>
                    @endisset
                @endforeach
            </div>
            <div class="sv-bodymap__text">
                <p class="sv-bodymap__eyebrow">Ghid rapid</p>
                <h2 id="sv-bodymap-title" class="sv-bodymap__title">Unde simțiți durere sau disconfort?</h2>
                <p class="sv-bodymap__lead">Alegeți zona și vedeți terapiile noastre pentru ea.</p>
                <div class="sv-bodymap__zones" role="tablist" aria-label="Zona corpului">
                    @foreach ($regions as $key => $region)
                        <button type="button" role="tab" class="sv-bodymap__zone" id="sv-zone-{{ $key }}" data-zone="{{ $key }}"
                            aria-controls="sv-panel-{{ $key }}" aria-selected="{{ $key === $first ? 'true' : 'false' }}" tabindex="{{ $key === $first ? '0' : '-1' }}">{{ $region['label'] }}</button>
                    @endforeach
                </div>
                @foreach ($regions as $key => $region)
                    <div class="sv-bodymap__panel {{ $key === $first ? 'is-active' : '' }}" id="sv-panel-{{ $key }}" role="tabpanel" aria-labelledby="sv-zone-{{ $key }}" data-zone="{{ $key }}" @if ($key !== $first) hidden @endif>
                        <ul>
                            @foreach ($region['links'] as $path => $label)
                                <li><a href="{{ $path }}">{{ $label }} <span aria-hidden="true">→</span></a></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <noscript><style>.sv-bodymap__panel[hidden]{display:block;margin-top:1rem}</style></noscript>
                <p class="sv-bodymap__note">Orientativ. Tratamentul potrivit îl stabilește medicul după consultație.</p>
            </div>
        </div>
    </div>
</section>
