<?php

namespace App\Modules\Site\Services;

use App\Core\Contracts\PublicBooking;
use App\Core\Settings\Settings;
use App\Modules\Clinic\Models\Location;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Site\Http\Controllers\PageController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Asamblează pagina publică completă: antet cu meniu, conținut, subsol, SEO, date structurate, consimțământ.
 */
class SiteView
{
    public function __construct(private readonly Renderer $renderer, private readonly Settings $settings, private readonly Anatomy $anatomy) {}

    public function page(Page $page, PageVersion $version, ?Request $request = null, bool $preview = false, ?string $notice = null): View
    {
        $rendered = $this->renderer->render($page, $version, $request, $preview);
        $settings = (array) ($version->settings ?? []);
        // Schițele anatomice se adaugă peste conținut; JSON-LD și descrierea se calculează din conținutul original.
        $content = $rendered['main'];
        $rendered['main'] = $this->anatomy->decorate($rendered['main'], $page->path);
        if ($page->path === '/') {
            $rendered['main'] = $this->anatomy->withBodyMap($rendered['main']);
        }

        return view($page->kind === Page::KIND_ARTICLE ? 'site::article' : 'site::page', $this->common($request, $preview) + [
            'page' => $page,
            'version' => $version,
            'main' => $rendered['main'],
            'pageCss' => $rendered['css'],
            'preloads' => $rendered['preloads'],
            'bodyClass' => $settings['body_class'] ?? '',
            'wrapClass' => $settings['wrap_class'] ?? 'oe_structure oe_empty',
            'seo' => $this->seo($page, $version, $request, ['main' => $content] + $rendered),
            'coverBgClass' => $rendered['cover_class'] ?? '',
            'articleSettings' => $settings,
            'notice' => $notice,
            'jsonLd' => $this->jsonLd($page, (array) ($version->seo ?? []), $content),
        ]);
    }

    /** O pagină generată de aplicație (nu din CMS), cu antetul, meniul și subsolul siteului. */
    public function standalone(string $view, array $data, Request $request, array $seo): View
    {
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $path = '/'.trim($request->getPathInfo(), '/');

        return view($view, $this->common($request, false) + [
            'seo' => $seo + ['canonical' => $base.$path, 'url' => $base.$path, 'og_title' => $seo['title'], 'og_type' => 'website', 'noindex' => false],
            'pageCss' => '', 'preloads' => [], 'bodyClass' => '', 'jsonLd' => $data['jsonLd'] ?? null,
        ] + $data);
    }

    public function notFound(?Request $request): View
    {
        return view('site::not-found', $this->common($request, false) + [
            'seo' => ['title' => 'Pagina nu a fost găsită | sevalia.ro', 'description' => null, 'canonical' => null, 'noindex' => true],
            'pageCss' => '', 'preloads' => [], 'bodyClass' => '', 'jsonLd' => null,
        ]);
    }

    private function common(?Request $request, bool $preview): array
    {
        $footer = Page::where('path', Page::FOOTER_PATH)->first();
        $footerVersion = $preview ? $footer?->currentVersion : $footer?->publishedVersion;
        $footerRendered = $footerVersion ? $this->renderer->renderBlocks($footerVersion->blocks, $request) : ['main' => '', 'css' => ''];

        $menu = MenuItem::where('menu', 'header')->whereNull('parent_id')->where('is_visible', true)->orderBy('sort')->with('children')->get();
        $megaCompiler = new HtmlCompiler;
        $mega = [];
        foreach ($menu as $item) {
            if ($item->kind === 'mega' && $item->mega_block) {
                $mega[$item->id] = $megaCompiler->compile($item->mega_block['html'] ?? '');
            }
        }
        $logo = ($id = $this->settings->get('site.logo_media_id')) ? Media::find($id) : null;

        return [
            'menu' => $menu,
            'megaMenus' => $mega,
            'footerHtml' => $footerRendered['main'],
            'footerCss' => $footerRendered['css'].$megaCompiler->css(),
            'logoUrl' => $logo?->url() ?? '/images/sevalia-logo.webp',
            'currentPath' => $request ? '/'.trim($request->getPathInfo(), '/') : '/',
            'consent' => ConsentConfig::forPage($request ? '/'.trim($request->getPathInfo(), '/') : '/'),
            'preview' => $preview,
            'showLanguageSelector' => (bool) $this->settings->get('site.language_selector', true),
            // Bara „Sună / Programare online” de pe mobil (Site → Setări), doar dacă programarea online funcționează.
            'ctaBar' => $this->ctaBar($request),
            // Codurile de verificare Search Console / Bing (setate din Marketing → Google și AI), doar pe prima pagină.
            'verification' => ($request ? '/'.trim($request->getPathInfo(), '/') : '/') === '/' ? array_filter([
                'google-site-verification' => $this->settings->get('marketing.verification.google'),
                'msvalidate.01' => $this->settings->get('marketing.verification.bing'),
            ]) : [],
        ];
    }

    private function seo(Page $page, PageVersion $version, ?Request $request, array $rendered = []): array
    {
        $seo = (array) ($version->seo ?? []);
        $html = (string) ($rendered['main'] ?? '');
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $og = ! empty($seo['og_image_media_id']) ? Media::find($seo['og_image_media_id']) : ($page->kind === Page::KIND_ARTICLE ? $page->cover : null);
        $isPaged = $request && $request->route('number');

        return [
            'title' => $seo['title'] ?? ($version->title.' | sevalia.ro'),
            // Fără descriere scrisă în CMS, se folosește începutul textului paginii (nimic inventat).
            'description' => ($seo['description'] ?? null) ?: self::excerpt($html),
            'keywords' => $seo['keywords'] ?? null,
            'canonical' => $seo['canonical'] ?? ($base.($page->path === '/' ? '/' : $page->path).($isPaged ? '/page/'.$request->route('number') : '')),
            'noindex' => (bool) ($seo['noindex'] ?? false),
            'og_title' => $seo['og_title'] ?? ($seo['title'] ?? $version->title),
            'og_image' => $og ? $base.$og->bestUrl(1200) : self::firstImage($rendered, $base),
            'og_type' => $page->kind === Page::KIND_ARTICLE ? 'article' : 'website',
            'url' => $base.($page->path === '/' ? '/' : $page->path),
        ];
    }

    /**
     * Date structurate doar din informații verificate: locațiile și programul (modulul Clinică), întrebările
     * frecvente exact cum apar pe pagină, numele serviciului (SEO al paginii) și firul de navigare.
     */
    private function jsonLd(Page $page, array $seo = [], string $html = ''): ?array
    {
        if ($page->path !== '/' && $page->path !== '/contactus' && $page->kind !== Page::KIND_ARTICLE) {
            $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
            $graph = [[
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasă', 'item' => $base.'/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $page->title, 'item' => $base.$page->path],
                ],
            ]];
            if (! empty($seo['service_name']) && ($loc = Location::active()->first())) {
                $graph[] = [
                    '@type' => 'Service',
                    'name' => $seo['service_name'],
                    'serviceType' => $seo['service_name'],
                    'areaServed' => [['@type' => 'City', 'name' => 'Târgu Mureș'], ['@type' => 'AdministrativeArea', 'name' => 'Județul Mureș']],
                    'url' => $base.$page->path,
                    // Fără descriere scrisă în CMS, se folosește începutul textului paginii (nimic inventat).
            'description' => ($seo['description'] ?? null) ?: self::excerpt($html),
                    'provider' => array_filter([
                        '@type' => 'MedicalClinic',
                        'name' => 'Sevalia Medica',
                        'url' => $base.'/',
                        'telephone' => $loc->phone,
                        'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $loc->address, 'addressLocality' => $loc->city, 'addressRegion' => $loc->county, 'addressCountry' => 'RO']),
                    ]),
                ];
            }
            if ($faq = self::faqFromHtml($html)) {
                $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $faq];
            }

            return ['@context' => 'https://schema.org', '@graph' => array_map(fn ($n) => array_filter($n), $graph)];
        }
        if ($page->path !== '/' && $page->path !== '/contactus') {
            return $page->kind === Page::KIND_ARTICLE ? [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $page->title,
                'datePublished' => $page->published_at?->toIso8601String(),
                'author' => $page->author_name ? ['@type' => 'Person', 'name' => $page->author_name] : null,
                'publisher' => ['@type' => 'MedicalOrganization', 'name' => 'Sevalia Medica'],
            ] : null;
        }
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $days = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $places = [];
        foreach (Location::active()->get() as $loc) {
            $hours = [];
            foreach ($days as $n => $day) {
                foreach ($loc->hoursFor($n) as [$open, $close]) {
                    $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $day, 'opens' => $open, 'closes' => $close];
                }
            }
            $places[] = array_filter([
                '@type' => 'MedicalClinic',
                'name' => 'Sevalia — '.$loc->name,
                'telephone' => $loc->phone,
                'email' => $loc->email,
                'url' => $base.'/',
                'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $loc->address, 'addressLocality' => $loc->city, 'addressRegion' => $loc->county, 'addressCountry' => 'RO']),
                'openingHoursSpecification' => $hours,
                'sameAs' => $this->sameAs() ?: null,
            ]);
        }

        return $places ? ['@context' => 'https://schema.org', '@graph' => $places] : null;
    }

    /** @return array{phone: ?string, booking: bool}|null */
    private function ctaBar(?Request $request): ?array
    {
        if (! $this->settings->get('site.cta_bar', true)) {
            return null;
        }
        $path = $request ? '/'.trim($request->getPathInfo(), '/') : '/';
        $booking = $path !== '/programare-online' && app(PublicBooking::class)->available();
        $phone = Location::active()->value('phone');

        return $booking || $phone ? ['phone' => $phone, 'booking' => $booking] : null;
    }

    /** Primul paragraf consistent al paginii, scurtat la ~155 de caractere la limită de cuvânt. */
    public static function excerpt(string $html, int $max = 155): ?string
    {
        if (! preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m)) {
            return null;
        }
        $text = '';
        foreach ($m[1] as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($p) < 50) {
                continue;
            }
            $text = trim($text.' '.$p);
            if (mb_strlen($text) >= 110) {
                break;
            }
        }
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space ?: $max - 1), " ,;:–-").'…';
    }

    /** Imaginea de distribuire implicită: imaginea principală a paginii (preîncărcată) sau prima din conținut. */
    private static function firstImage(array $rendered, string $base): ?string
    {
        $src = $rendered['preloads'][0]['href'] ?? null;
        if (! $src && preg_match('#<img\b[^>]*\ssrc="(/[^"]+)"#i', (string) ($rendered['main'] ?? ''), $m)) {
            $src = html_entity_decode($m[1]);
        }

        return $src && str_starts_with($src, '/') ? $base.$src : null;
    }

    /**
     * Profilurile oficiale ale clinicii (Google Business și rețelele sociale din setări), pentru legarea entității.
     *
     * @return list<string>
     */
    private function sameAs(): array
    {
        $links = array_merge(PageController::SOCIAL_DEFAULTS, (array) $this->settings->get('site.social', []));
        $links[] = $this->settings->get('marketing.gbp_url');

        return array_values(array_unique(array_filter($links, fn ($l) => is_string($l) && str_starts_with($l, 'https://'))));
    }

    /**
     * Întrebările frecvente din blocurile acordeon (s_faq_collapse) ale paginii, textul exact afișat.
     *
     * @return list<array<string, mixed>>
     */
    public static function faqFromHtml(string $html): array
    {
        if ($html === '' || ! str_contains($html, 'accordion')) {
            return [];
        }
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($doc);
        $items = [];
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " accordion ")]//*[contains(concat(" ", normalize-space(@class), " "), " card ")]') as $card) {
            $q = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " card-header ")]', $card)->item(0);
            $a = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " card-body ")]', $card)->item(0);
            $question = $q ? trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $q->textContent))) : '';
            $answer = $a ? trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $a->textContent))) : '';
            if ($question !== '' && $answer !== '') {
                $items[] = ['@type' => 'Question', 'name' => $question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]];
            }
        }

        return $items;
    }
}
