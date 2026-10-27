<!DOCTYPE html>
<html lang="ro-RO" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    @if (! empty($seo['description']))<meta name="description" content="{{ $seo['description'] }}">@endif
    @if (! empty($seo['keywords']))<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
    @if (! empty($seo['noindex']) || $preview)<meta name="robots" content="noindex, nofollow">@endif
    @if (! empty($seo['canonical']) && ! $preview)<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
    @isset($seo['url'])
        <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
        <meta property="og:title" content="{{ $seo['og_title'] }}">
        <meta property="og:site_name" content="sevalia.ro">
        <meta property="og:url" content="{{ $seo['url'] }}">
        @if (! empty($seo['og_image']))<meta property="og:image" content="{{ $seo['og_image'] }}">@endif
        @if (! empty($seo['description']))<meta property="og:description" content="{{ $seo['description'] }}">@endif
        <meta name="twitter:card" content="summary_large_image">
    @endisset
    @foreach ($verification ?? [] as $name => $content)<meta name="{{ $name }}" content="{{ $content }}">@endforeach
    <link rel="icon" href="/favicon.ico">
    @foreach ($preloads as $p)
        <link rel="preload" as="image" href="{{ $p['href'] }}" media="{{ $p['media'] }}" fetchpriority="high">
    @endforeach
    @vite(['resources/css/site.css'])
    @if (($pageCss ?? '') !== '' || ($footerCss ?? '') !== '')
        <style>{!! $pageCss ?? '' !!}{!! $footerCss ?? '' !!}</style>
    @endif
    @if ($consent['google'])
        {{-- Google Consent Mode v2: implicit totul „denied”; se actualizează doar după alegerea vizitatorului. --}}
        <script nonce="{{ app('csp-nonce') }}">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});</script>
    @endif
    @if (! empty($jsonLd))
        <script type="application/ld+json" nonce="{{ app('csp-nonce') }}">{!! json_encode(array_filter($jsonLd), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endif
</head>
<body>
<div id="wrapwrap" class="{{ $bodyClass }}">
    <a class="o_skip_to_content btn btn-primary rounded-0 visually-hidden-focusable position-absolute start-0" href="#wrap">Sari la conținut</a>
    @include('site::partials.header')
    <main>
        @yield('main')
    </main>
    <footer id="bottom" class="o_footer o_colored_level o_cc">
        <div id="footer" class="oe_structure oe_structure_solo">
            {!! $footerHtml !!}
        </div>
    </footer>
</div>
@include('site::partials.cookie-banner')
<script type="application/json" id="site-consent-config" nonce="{{ app('csp-nonce') }}">{!! json_encode(['tags' => $consent['tags'], 'cookie' => \App\Modules\Site\Services\ConsentConfig::COOKIE], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) !!}</script>
@vite(['resources/js/site.js'])
</body>
</html>
