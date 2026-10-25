<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Redirect;

/**
 * Semnalează linkurile interne care duc la pagini inexistente (ex. /neurologie, /dry-needling), în conținut și meniuri,
 * ca editorul să creeze pagina sau să schimbe linkul. Nu creează și nu modifică nimic.
 */
class LinkChecker
{
    /** Prefixe gestionate de alte rute (nu sunt pagini CMS). */
    private const ROUTED_PREFIXES = ['/website/social/', '/blog/blog-sevalia-1/page/', '/formular/', '/media/', '/images/', '/build/', '/panou', '/en'];

    private ?array $known = null;

    /** @return list<string> căile interne rupte din HTML-ul dat */
    public function brokenIn(string $html): array
    {
        preg_match_all('/href="(\/[^"#?]*)/', $html, $m);
        $broken = [];
        foreach (array_unique($m[1]) as $path) {
            $path = '/'.trim(rawurldecode(html_entity_decode($path)), '/');
            if (! $this->exists($path)) {
                $broken[] = $path;
            }
        }

        return array_values(array_unique($broken));
    }

    /** @return list<string> */
    public function brokenInBlocks(array $blocks): array
    {
        $html = '';
        foreach ($blocks as $block) {
            $html .= ($block['html'] ?? '');
        }

        return $this->brokenIn($html);
    }

    /** @return array<int, list<string>> id element meniu => linkuri rupte */
    public function brokenInMenus(): array
    {
        $out = [];
        foreach (MenuItem::all() as $item) {
            $links = [];
            if ($item->url && str_starts_with($item->url, '/') && ! $this->exists('/'.trim($item->url, '/'))) {
                $links[] = $item->url;
            }
            if ($item->mega_block) {
                $links = [...$links, ...$this->brokenIn($item->mega_block['html'] ?? '')];
            }
            if ($links) {
                $out[$item->id] = array_values(array_unique($links));
            }
        }

        return $out;
    }

    public function exists(string $path): bool
    {
        if ($path === '/' || $path === '') {
            return true;
        }
        foreach (self::ROUTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        if (in_array($path, ['/sitemap.xml', '/robots.txt', '/favicon.ico'], true)) {
            return true;
        }
        $this->known ??= [
            ...Page::published()->whereIn('kind', [Page::KIND_PAGE, Page::KIND_ARTICLE])->pluck('path')->all(),
            ...Redirect::where('is_active', true)->pluck('source_path')->all(),
        ];

        return in_array($path, $this->known, true);
    }
}
