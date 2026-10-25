<?php

namespace App\Modules\Site\Services;

use App\Core\Settings\Settings;
use App\Modules\Clinic\Models\Location;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Asamblează pagina publică completă: antet cu meniu, conținut, subsol, SEO, date structurate, consimțământ.
 */
class SiteView
{
    public function __construct(private readonly Renderer $renderer, private readonly Settings $settings) {}

    public function page(Page $page, PageVersion $version, ?Request $request = null, bool $preview = false, ?string $notice = null): View
    {
        $rendered = $this->renderer->render($page, $version, $request, $preview);
        $settings = (array) ($version->settings ?? []);

        return view($page->kind === Page::KIND_ARTICLE ? 'site::article' : 'site::page', $this->common($request, $preview) + [
            'page' => $page,
            'version' => $version,
            'main' => $rendered['main'],
            'pageCss' => $rendered['css'],
            'preloads' => $rendered['preloads'],
            'bodyClass' => $settings['body_class'] ?? '',
            'wrapClass' => $settings['wrap_class'] ?? 'oe_structure oe_empty',
            'seo' => $this->seo($page, $version, $request),
            'coverBgClass' => $rendered['cover_class'] ?? '',
            'articleSettings' => $settings,
            'notice' => $notice,
            'jsonLd' => $this->jsonLd($page),
        ]);
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
        ];
    }

    private function seo(Page $page, PageVersion $version, ?Request $request): array
    {
        $seo = (array) ($version->seo ?? []);
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $og = ! empty($seo['og_image_media_id']) ? Media::find($seo['og_image_media_id']) : ($page->kind === Page::KIND_ARTICLE ? $page->cover : null);
        $isPaged = $request && $request->route('number');

        return [
            'title' => $seo['title'] ?? ($version->title.' | sevalia.ro'),
            'description' => $seo['description'] ?? null,
            'keywords' => $seo['keywords'] ?? null,
            'canonical' => $seo['canonical'] ?? ($base.($page->path === '/' ? '/' : $page->path).($isPaged ? '/page/'.$request->route('number') : '')),
            'noindex' => (bool) ($seo['noindex'] ?? false),
            'og_title' => $seo['og_title'] ?? ($seo['title'] ?? $version->title),
            'og_image' => $og ? $base.$og->bestUrl(1200) : null,
            'og_type' => $page->kind === Page::KIND_ARTICLE ? 'article' : 'website',
            'url' => $base.($page->path === '/' ? '/' : $page->path),
        ];
    }

    /** Date structurate doar din informații verificate (locațiile și programul din modulul Clinică). */
    private function jsonLd(Page $page): ?array
    {
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
            ]);
        }

        return $places ? ['@context' => 'https://schema.org', '@graph' => $places] : null;
    }
}
