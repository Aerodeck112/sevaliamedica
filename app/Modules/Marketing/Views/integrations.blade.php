@extends('layouts.panou')
@section('title', 'Google și AI')
@section('content')
    <x-panou.page-head title="Google, motoare de căutare și asistenți AI"
        sub="Tot ce ține de vizibilitate într-un singur loc. Siteul nu primește parole sau acces la contul Google al clinicii: verificarea se face printr-un cod, iar rapoartele rămân în Search Console și Google Business." />

    @unless ($production)
        <div class="alert alert--warn" role="note"><x-panou.icon name="warn" /><div>Acesta nu este siteul de producție: robots.txt blochează indexarea și IndexNow nu trimite nimic. Setările se pot pregăti aici și se aplică la fel în producție.</div></div>
    @endunless

    <form method="post" action="{{ route('panou.marketing.integrations.update') }}" class="stack">
        @csrf @method('put')
        <div class="grid grid--2">
            <x-panou.panel title="1. Google Search Console">
                <div class="stack">
                    <p class="small">Arată pentru ce căutări apare siteul, pe ce poziție și câte clicuri primește. Pași: deschideți <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a> → Adăugați proprietatea → metoda „Etichetă HTML” → copiați eticheta aici și salvați → apăsați „Verificați” în Search Console. Apoi, la „Sitemapuri”, trimiteți o singură dată <code>{{ $base }}/sitemap.xml</code>.</p>
                    <x-panou.field name="google" label="Eticheta sau codul de verificare Google" :value="$s['google']" placeholder='<meta name="google-site-verification" content="…">' />
                    <x-panou.field name="gsc_property" label="Proprietatea din Search Console (pentru linkurile de inspectare)" :value="$s['gsc_property']" placeholder="sc-domain:sevalia.ro" hint="Forma „sc-domain:sevalia.ro” (proprietate de domeniu) sau „https://www.sevalia.ro/”." />
                </div>
            </x-panou.panel>

            <x-panou.panel title="2. Google Business Profile (Maps)">
                <div class="stack">
                    <p class="small">Profilul de pe Google Maps decide în mare măsură căutările locale („kinetoterapie Târgu Mureș”). Linkul se adaugă în datele structurate ale siteului (<code>sameAs</code>), ca Google să lege siteul de profil. Țineți profilul complet: categorii, program, fotografii, răspunsuri la recenzii.</p>
                    <x-panou.field name="gbp_url" type="url" label="Linkul profilului Google Business / Google Maps" :value="$s['gbp_url']" placeholder="https://maps.app.goo.gl/…" />
                    <p class="small muted">Linkul pentru cererea de recenzii (codul QR de pe tabletă) se setează în <a href="{{ route('panou.kiosk.devices') }}">Tablete recepție → Setări</a>.</p>
                </div>
            </x-panou.panel>

            <x-panou.panel title="3. Bing Webmaster Tools și IndexNow">
                <div class="stack">
                    <p class="small">Bing alimentează și Copilot și o parte din căutările ChatGPT. Verificare la <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a> (se poate importa direct din Search Console).</p>
                    <x-panou.field name="bing" label="Eticheta sau codul de verificare Bing (msvalidate.01)" :value="$s['bing']" />
                    <x-panou.field name="indexnow" type="checkbox" label="Anunță automat paginile publicate sau modificate prin IndexNow (Bing, Yandex, Seznam, Naver)" :value="$s['indexnow']" />
                    <p class="small muted">Fișierul cheii: <span class="code-copy">{{ $indexNowKeyUrl }}</span>. Google nu folosește IndexNow; pentru Google contează sitemapul și „Solicitați indexarea” din Search Console.</p>
                </div>
            </x-panou.panel>

            <x-panou.panel title="4. Vizibilitate în asistenții AI">
                <div class="stack">
                    <x-panou.field name="ai" type="select" label="Accesul crawlerilor AI (robots.txt)" :value="$s['ai']" :options="\App\Modules\Marketing\Http\Controllers\IntegrationsController::AI_MODES" :placeholder="false" />
                    <x-panou.field name="llms" type="checkbox" label="Publică /llms.txt — rezumatul siteului pentru asistenții AI" :value="$s['llms']" />
                    <x-panou.field name="llms_summary" label="Rezumatul clinicii (o frază)" :value="$s['llms_summary']" hint="Gol = descrierea SEO a paginii principale. Doar informații adevărate și verificabile." />
                    <x-panou.field name="llms_notes" type="textarea" label="Note suplimentare pentru asistenții AI (opțional)" :value="$s['llms_notes']" rows="3" hint="Text simplu, public. Nu introduceți tarife sau promisiuni neconfirmate." />
                    <p class="small muted">Previzualizare: <a href="/llms.txt" target="_blank" rel="noopener">/llms.txt</a> · <a href="/robots.txt" target="_blank" rel="noopener">/robots.txt</a>. llms.txt este o convenție propusă, nu un standard: ajută, dar nu garantează citarea în răspunsurile AI.</p>
                </div>
            </x-panou.panel>
        </div>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează</button></div>
    </form>

    <div class="grid grid--2" style="margin-top:1.25rem">
        <x-panou.panel title="Reindexare în Google" :flush="true">
            <p class="small" style="padding:1rem 1.25rem 0">După o modificare importantă: deschideți pagina în Search Console și apăsați „Solicitați indexarea”. Google nu oferă un API pentru această acțiune în cazul siteurilor obișnuite, iar „ping”-ul de sitemap a fost retras de Google în 2023 — de aceea linkurile de mai jos duc direct la inspectarea fiecărei pagini.</p>
            <table class="table">
                <tbody>
                @foreach ($inspect as $i)
                    <tr>
                        <td><a href="{{ $i['path'] }}" target="_blank" rel="noopener">{{ $i['path'] }}</a></td>
                        <td style="text-align:right">
                            @if ($i['url'])<a class="btn btn--small" href="{{ $i['url'] }}" target="_blank" rel="noopener">Inspectează în Search Console</a>
                            @else<span class="small muted">completați proprietatea Search Console</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-panou.panel>

        <x-panou.panel title="IndexNow — trimiteri" :flush="true">
            <x-slot:actions>
                <form method="post" action="{{ route('panou.marketing.integrations.indexnow') }}">@csrf
                    <button class="btn btn--small" type="submit" @disabled(! $s['indexnow'])>Trimite toate paginile</button>
                </form>
            </x-slot:actions>
            <p class="small" style="padding:1rem 1.25rem 0">În coadă: <strong>{{ $pending }}</strong> {{ $pending === 1 ? 'adresă' : 'adrese' }} (se trimit la 5 minute, din cron).</p>
            @if ($submissions->isEmpty())
                <p class="small muted" style="padding:0 1.25rem 1rem">Nicio trimitere încă.</p>
            @else
                <table class="table"><thead><tr><th>Data</th><th>Adrese</th><th>Rezultat</th></tr></thead><tbody>
                @foreach ($submissions as $sub)
                    <tr><td class="num">{{ $sub->created_at->format('d.m.Y H:i') }}</td><td class="num">{{ $sub->urls_count }}</td>
                        <td><x-panou.badge :tone="in_array($sub->status_code, [200, 202]) ? 'ok' : 'warn'">{{ $sub->status_code ?? '—' }}</x-panou.badge> {{ $sub->result }}</td></tr>
                @endforeach
                </tbody></table>
            @endif
        </x-panou.panel>

        <x-panou.panel title="Măsurare și reclame" :flush="true">
            <p class="small" style="padding:1rem 1.25rem 0">Google Analytics 4, Google Ads și Meta Pixel se configurează în <a href="{{ route('panou.cms.tracking.edit') }}">Site → Etichete și cookie</a> și pornesc doar după acordul vizitatorului (Consent Mode v2).</p>
            <ul class="seo-list">
                @forelse ($ga4 as $t)
                    <li><x-panou.badge tone="ok">activă</x-panou.badge> {{ \App\Modules\Site\Services\ConsentConfig::TAG_TYPES[$t['type']] ?? $t['type'] }} — {{ $t['id'] }}</li>
                @empty
                    <li><x-panou.badge tone="warn">lipsă</x-panou.badge> Nicio etichetă activă.</li>
                @endforelse
            </ul>
        </x-panou.panel>

        <x-panou.panel title="Unelte utile" :flush="true">
            <ul class="seo-list">
                <li><a href="https://pagespeed.web.dev/analysis?url={{ rawurlencode($base.'/') }}" target="_blank" rel="noopener">PageSpeed Insights</a> — viteza pe mobil și desktop, din date reale Chrome.</li>
                <li><a href="https://search.google.com/test/rich-results?url={{ rawurlencode($base.'/') }}" target="_blank" rel="noopener">Testul pentru rezultate îmbogățite</a> — datele structurate (clinică, servicii, întrebări frecvente).</li>
                <li><a href="https://business.google.com/" target="_blank" rel="noopener">Google Business Profile</a> — program, fotografii, postări, răspunsuri la recenzii.</li>
                <li><a href="https://ads.google.com/" target="_blank" rel="noopener">Google Ads</a> — campanii; conversiile se măsoară doar cu acordul vizitatorului.</li>
                <li><a href="{{ route('panou.marketing.seo') }}">Audit SEO automat</a> — verificarea internă a tuturor paginilor.</li>
            </ul>
        </x-panou.panel>
    </div>
@endsection
