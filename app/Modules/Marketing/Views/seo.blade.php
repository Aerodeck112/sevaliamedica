@extends('layouts.panou')
@section('title', 'Audit SEO')
@section('content')
    @php
        $tone = fn (?int $s) => $s === null ? null : ($s >= 90 ? 'ok' : ($s >= 70 ? 'warn' : 'danger'));
        $labels = ['error' => 'eroare', 'warn' => 'avertisment', 'info' => 'informație'];
        $plural = ['error' => 'erori', 'warn' => 'avertismente', 'info' => 'informații'];
        $tones = ['error' => 'danger', 'warn' => 'warn', 'info' => 'info'];
        $canEdit = auth()->user()->can('cms.edit');
    @endphp
    <x-panou.page-head title="Audit SEO automat"
        sub="Pilotul automat verifică în fiecare noapte toate paginile publicate: titluri, descrieri, H1, texte alternative, linkuri rupte, date structurate, trimiteri la siteul vechi și cuvintele cheie urmărite. Puteți porni oricând o verificare.">
        <x-slot:actions>
            <form method="post" action="{{ route('panou.marketing.seo.start') }}">@csrf
                <button class="btn btn--primary" type="submit"><x-panou.icon name="refresh" /> Verifică acum</button>
            </form>
        </x-slot:actions>
    </x-panou.page-head>

    @if ($running)
        <div class="alert alert--info" role="status">
            <x-panou.icon name="clock" />
            <div>
                <strong>Verificare în curs:</strong> {{ $running->pages_done }} din {{ $running->pages_total }} pagini. Continuă automat din cron (la minut).
                <form method="post" action="{{ route('panou.marketing.seo.continue', $running) }}" style="display:inline">@csrf
                    <button class="btn btn--small" type="submit">Continuă acum</button>
                </form>
            </div>
        </div>
    @endif

    @if (! $run)
        <x-panou.empty title="Încă nu există un audit">Apăsați „Verifică acum” pentru prima verificare. Apoi pilotul rulează automat în fiecare noapte.</x-panou.empty>
    @else
        <x-panou.panel :flush="true">
            <div class="metrics">
                <div class="metric">
                    <span class="metric__value num seo-score seo-score--{{ $tone($run->score) }}">{{ $run->score ?? '—' }}</span>
                    <span class="metric__label">Scor SEO al siteului</span>
                    <span class="metric__help">
                        @if ($previous && $previous->score !== null)
                            @php $d = $run->score - $previous->score; @endphp
                            {{ $d === 0 ? 'neschimbat' : ($d > 0 ? '+'.$d : $d) }} față de {{ $previous->finished_at->format('d.m') }}
                        @else
                            media celor {{ $run->pages_total }} pagini
                        @endif
                    </span>
                </div>
                <div class="metric"><span class="metric__value num">{{ $run->pages_total }}</span><span class="metric__label">Pagini verificate</span><span class="metric__help">{{ $run->finished_at->format('d.m.Y H:i') }} · {{ $run->trigger === 'cron' ? 'automat' : 'manual' }}</span></div>
                <div class="metric"><a href="?gravitate=error" class="metric__value num" style="color:var(--danger, #b42318)">{{ $run->errors_count }}</a><span class="metric__label">Erori</span><span class="metric__help">de corectat întâi</span></div>
                <div class="metric"><a href="?gravitate=warn" class="metric__value num">{{ $run->warnings_count }}</a><span class="metric__label">Avertismente</span><span class="metric__help">îmbunătățiri recomandate</span></div>
                <div class="metric">
                    <span class="metric__label">Evoluție</span>
                    <div class="seo-trend" role="img" aria-label="Scorul ultimelor {{ $history->count() }} audituri">
                        @foreach ($history as $h)
                            <a href="?rulare={{ $h->id }}" class="seo-trend__bar seo-trend__bar--{{ $tone($h->score) }} {{ $h->id === $run->id ? 'is-current' : '' }}" style="height:{{ max(6, (int) $h->score) }}%" title="{{ $h->finished_at->format('d.m.Y') }}: {{ $h->score }}"></a>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-panou.panel>

        <div class="grid grid--2" style="margin-top:1.25rem">
            <x-panou.panel title="Verificări la nivel de site" :flush="true">
                <ul class="seo-list">
                    @forelse ($run->site_checks['site'] ?? [] as $c)
                        <li><x-panou.badge :tone="$tones[$c['severity']]">{{ $labels[$c['severity']] }}</x-panou.badge> {{ $c['message'] }}</li>
                    @empty
                        <li><x-panou.badge tone="ok">în regulă</x-panou.badge> Search Console, Google Business, IndexNow și llms.txt sunt configurate.</li>
                    @endforelse
                </ul>
                @can('marketing.integrations')
                    <p class="small" style="padding:0 1.25rem 1rem"><a href="{{ route('panou.marketing.integrations') }}">Configurați integrările Google și AI →</a></p>
                @endcan
            </x-panou.panel>
            <x-panou.panel title="Ce merită corectat întâi" :flush="true">
                <ul class="seo-list">
                    @forelse (array_slice($frequent, 0, 8, true) as $code => $f)
                        <li><x-panou.badge :tone="$tones[$f['severity']]">{{ $f['count'] }} {{ $f['count'] === 1 ? 'pagină' : 'pagini' }}</x-panou.badge> {{ $f['example'] }}</li>
                    @empty
                        <li><x-panou.badge tone="ok">excelent</x-panou.badge> Nicio eroare sau avertisment pe pagini.</li>
                    @endforelse
                </ul>
            </x-panou.panel>
        </div>

        @php $kw = collect($run->site_checks['keywords'] ?? []); @endphp
        @if ($kw->isNotEmpty())
            <x-panou.panel title="Cuvinte cheie urmărite" :flush="true" style="margin-top:1.25rem">
                <x-slot:actions><a class="btn btn--small" href="{{ route('panou.marketing.keywords') }}">Administrează</a></x-slot:actions>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Expresie</th><th>Pagina</th><th>Titlu</th><th>H1</th><th>Descriere</th><th>Text</th><th>Potrivire</th></tr></thead>
                    <tbody>
                    @foreach ($kw->sortBy('score') as $k)
                        <tr>
                            <td><strong>{{ $k['phrase'] }}</strong></td>
                            <td class="small"><a href="{{ $k['path'] }}" target="_blank" rel="noopener">{{ $k['path'] }}</a></td>
                            @if (! empty($k['fields']))
                                @foreach (['title', 'h1', 'description', 'text'] as $f)
                                    <td>@include('marketing::partials.match', ['value' => $k['fields'][$f] ?? 'lipsa'])</td>
                                @endforeach
                            @else
                                <td colspan="4" class="muted small">—</td>
                            @endif
                            <td>
                                <x-panou.badge :tone="$tone($k['score'])">{{ $k['score'] }}%</x-panou.badge>
                                @if ($k['problem'])<br><span class="small" style="color:#b42318">{{ $k['problem'] }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <p class="small muted" style="padding:.75rem 1.25rem">„exact” = expresia apare întocmai (fără diacritice, „Tg.” = „Târgu”); „parțial” = toate cuvintele apar, în altă ordine. Poziția în Google depinde și de factori din afara siteului (recenzii, profilul Google Business, linkuri), pe care auditul nu îi poate măsura.</p>
            </x-panou.panel>
        @endif

        <x-panou.panel title="Pagini{{ $severity ? ' cu '.($severity === 'error' ? 'erori' : 'avertismente') : '' }}" :flush="true" style="margin-top:1.25rem">
            <x-slot:actions>
                @if ($severity)<a class="btn btn--small" href="{{ route('panou.marketing.seo') }}">Toate paginile</a>@endif
            </x-slot:actions>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Scor</th><th>Pagina</th><th>Probleme</th><th></th></tr></thead>
                <tbody>
                @foreach ($pages as $p)
                    @php $prev = $previousScores[$p->path] ?? null; $issues = collect($p->issues)->sortBy(fn ($i) => array_search($i['severity'], ['error', 'warn', 'info'])); @endphp
                    <tr>
                        <td class="num" style="white-space:nowrap">
                            <x-panou.badge :tone="$tone($p->score)">{{ $p->score }}</x-panou.badge>
                            @if ($prev !== null && $prev !== $p->score)<span class="small {{ $p->score > $prev ? '' : 'muted' }}">{{ $p->score > $prev ? '▲' : '▼' }}{{ abs($p->score - $prev) }}</span>@endif
                        </td>
                        <td>
                            <a href="{{ $p->path }}" target="_blank" rel="noopener">{{ $p->path }}</a><br>
                            <span class="small muted">{{ \Illuminate\Support\Str::limit($p->metrics['title'] ?? $p->title, 80) }}</span>
                        </td>
                        <td>
                            @if ($issues->isEmpty())
                                <span class="muted small">nicio problemă</span>
                            @else
                                <details>
                                    <summary class="small">
                                        @foreach (['error', 'warn', 'info'] as $sev)
                                            @php $n = $p->countBy($sev); @endphp
                                            @if ($n > 0)
                                                <x-panou.badge :tone="$tones[$sev]">{{ $n }} {{ $n > 1 ? $plural[$sev] : $labels[$sev] }}</x-panou.badge>
                                            @endif
                                        @endforeach
                                    </summary>
                                    <ul class="seo-issues">
                                        @foreach ($issues as $i)<li class="seo-issues__{{ $i['severity'] }}">{{ $i['message'] }}</li>@endforeach
                                    </ul>
                                </details>
                            @endif
                        </td>
                        <td style="text-align:right">@if ($canEdit && $p->page_id)<a class="btn btn--small" href="{{ route('panou.cms.pages.edit', $p->page_id) }}">Editează</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </x-panou.panel>
    @endif
@endsection
