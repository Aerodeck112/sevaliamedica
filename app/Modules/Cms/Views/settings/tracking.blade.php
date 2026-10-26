@extends('layouts.panou')
@php
    use App\Modules\Site\Services\ConsentConfig;
    $rows = array_merge($tags, array_fill(0, 2, ['type' => '', 'id' => '', 'category' => 'marketing', 'active' => false, 'excluded' => ConsentConfig::DEFAULT_EXCLUDED]));
@endphp
@section('title', 'Etichete și cookie')
@section('content')
    <x-panou.page-head title="Etichete de măsurare și marketing"
        sub="Google tag (Google Ads, Google Analytics 4) și Meta Pixel. Se încarcă numai după ce vizitatorul acceptă categoria etichetei; până atunci Google Consent Mode rămâne „denied”." />
    <div class="alert alert--warn" role="note">
        <x-panou.icon name="warn" />
        <div><strong>Paginile cu formulare pot conține date de sănătate.</strong> Implicit, etichetele sunt excluse de pe <code>/contactus</code>, pagina de confirmare și landingul EMSELLA. Dacă le includeți, verificați întâi cu responsabilul pentru protecția datelor. Siteul nu trimite către etichete date din formulare și nici evenimente de conversie cu date personale.</div>
    </div>
    <form method="post" action="{{ route('panou.cms.tracking.update') }}" class="stack">
        @csrf @method('put')
        @foreach ($rows as $i => $tag)
            <x-panou.panel :title="$tag['id'] ? ConsentConfig::TAG_TYPES[$tag['type']].' — '.$tag['id'] : 'Etichetă nouă'">
                <div class="form-grid">
                    <x-panou.field name="tags[{{ $i }}][type]" type="select" label="Tip" :value="$tag['type']" :options="ConsentConfig::TAG_TYPES" placeholder="— fără —" />
                    <x-panou.field name="tags[{{ $i }}][id]" label="Identificator" :value="$tag['id']" placeholder="AW-…, G-… sau ID Meta" />
                    <x-panou.field name="tags[{{ $i }}][category]" type="select" label="Categoria de consimțământ" :value="$tag['category']" :options="ConsentConfig::CATEGORIES" :placeholder="false" />
                    <x-panou.field name="tags[{{ $i }}][active]" type="checkbox" label="Activă" :value="$tag['active']" />
                    <x-panou.field name="tags[{{ $i }}][excluded]" type="textarea" label="Pagini excluse (câte una pe rând)" :value="implode(PHP_EOL, $tag['excluded'])" full rows="3" />
                </div>
            </x-panou.panel>
        @endforeach
        <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează etichetele</button></div>
    </form>
    <p class="small muted">Politica de securitate a conținutului (CSP) permite domeniile unei etichete doar pe paginile unde eticheta e activă. Textele bannerului de cookie se editează în <a href="{{ route('panou.cms.settings.edit') }}">Setări site</a>.</p>
@endsection
