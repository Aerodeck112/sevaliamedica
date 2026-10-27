@extends('layouts.panou')
@section('title', 'Setări site')
@section('content')
    <x-panou.page-head title="Setări site" sub="Opțiuni generale ale siteului public. Adresele, telefoanele și programul se administrează în Administrare › Locații." />
    <form method="post" action="{{ route('panou.cms.settings.update') }}" class="stack" style="max-width:880px">
        @csrf @method('put')
        <x-panou.panel title="Formularul de contact">
            <x-panou.field name="contact_birthdate" type="checkbox" label="Afișează câmpul „Data nașterii”" :value="$birthdate" />
            <p class="small muted" style="margin-top:.5rem">Implicit ascuns: pentru o solicitare de programare nu este necesară data nașterii (minimizarea datelor). Activați doar dacă recepția are nevoie de ea înainte de primul contact.</p>
        </x-panou.panel>
        <x-panou.panel title="Limbă">
            <div class="stack">
                <x-panou.field name="en_redirect" type="checkbox" label="Redirecționează versiunea /en către pagina echivalentă în română (301)" :value="$enRedirect" />
                <x-panou.field name="language_selector" type="checkbox" label="Afișează selectorul de limbă în antet (ca pe siteul original)" :value="$languageSelector" />
                <x-panou.field name="anatomy" type="checkbox" label="Schițe anatomice animate pe copertele paginilor de servicii" :value="$anatomy" />
                <x-panou.field name="body_map" type="checkbox" label="Harta „Unde simțiți disconfort?” pe prima pagină" :value="$bodyMap" />
                <x-panou.field name="cta_bar" type="checkbox" label="Bară fixă „Sună acum / Programare online” pe telefoane" :value="$ctaBar" />
                <x-panou.field name="body_map_after" type="number" label="Harta apare după secțiunea nr." :value="$bodyMapAfter" hint="Numărul secțiunii primei pagini după care se afișează harta (0 = la început)." />
                <p class="small muted">Decizie provizorie a clinicii (2026-10-25): versiunea /en generată de Odoo conținea majoritar text în română; până la o traducere reală, adresele /en duc la pagina în română.</p>
            </div>
        </x-panou.panel>
        <x-panou.panel title="Rețele sociale">
            <div class="form-grid">
                @foreach ($social as $network => $url)
                    <x-panou.field name="social[{{ $network }}]" type="url" :label="ucfirst($network)" :value="$url" />
                @endforeach
            </div>
            <p class="small muted">Linkurile din subsol (/website/social/…) duc la aceste adrese.</p>
        </x-panou.panel>
        <x-panou.panel title="Textele bannerului de cookie">
            <div class="stack">
                <x-panou.field name="cookie[title]" label="Titlu" :value="$cookie['title']" />
                <x-panou.field name="cookie[body]" type="textarea" label="Text" :value="$cookie['body']" rows="3" />
                <div class="form-grid">
                    <x-panou.field name="cookie[accept]" label="Butonul „Accept”" :value="$cookie['accept']" />
                    <x-panou.field name="cookie[reject]" label="Butonul „Refuz”" :value="$cookie['reject']" />
                    <x-panou.field name="cookie[customize]" label="Butonul „Personalizez”" :value="$cookie['customize']" />
                    <x-panou.field name="cookie[save]" label="Butonul „Salvez”" :value="$cookie['save']" />
                </div>
                <x-panou.field name="cookie[necessary]" label="Descriere: necesare" :value="$cookie['necessary']" />
                <x-panou.field name="cookie[statistici]" label="Descriere: statistici" :value="$cookie['statistici']" />
                <x-panou.field name="cookie[marketing]" label="Descriere: marketing" :value="$cookie['marketing']" />
                <x-panou.field name="cookie[policy_url]" label="Link politică" :value="$cookie['policy_url']" />
                <p class="small muted">Butonul de refuz are aceeași mărime și aceeași poziție ca acceptarea. Etichetele de măsurare se configurează în <a href="{{ route('panou.cms.tracking.edit') }}">Etichete și cookie</a>.</p>
            </div>
        </x-panou.panel>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează setările</button></div>
    </form>
@endsection
