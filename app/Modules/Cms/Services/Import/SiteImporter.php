<?php

namespace App\Modules\Cms\Services\Import;

use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SectionTemplate;
use App\Modules\Cms\Services\Blocks\Slots;
use App\Modules\Cms\Services\Html\Dom;
use App\Modules\Cms\Services\Html\StructureSanitizer;
use App\Modules\Cms\Services\MediaLibrary;
use App\Modules\Cms\Services\PageService;
use Carbon\Carbon;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importul conținutului din instantaneul siteului Odoo (database/content/odoo-snapshot).
 *
 * Idempotent: o pagină se reimportă doar dacă nu a fost modificată din CMS după importul anterior
 * (hash-ul versiunii curente = hash-ul importului). Paginile editate sunt raportate și lăsate neatinse.
 */
class SiteImporter
{
    public const BASE = 'https://www.sevalia.ro';

    /** Pagini ale platformei Odoo, fără conținut al clinicii. */
    public const SYSTEM_PATHS = ['/website/info'];

    private array $mediaByPath = [];

    private array $report = ['pagini_create' => [], 'pagini_actualizate' => [], 'pagini_neschimbate' => [], 'pagini_editate_sarite' => [], 'imagini' => 0, 'imagini_lipsa' => [], 'atentionari' => []];

    private array $templatesSeen = [];

    public function __construct(private readonly MediaLibrary $media, private readonly PageService $pages) {}

    public function snapshotPath(string $rel = ''): string
    {
        return database_path('content/odoo-snapshot'.($rel !== '' ? '/'.$rel : ''));
    }

    public function run(bool $withMedia = true): array
    {
        if ($withMedia) {
            $this->importMedia();
        } else {
            $this->loadMediaMap();
        }
        $this->importFiles();

        $pages = json_decode(file_get_contents($this->snapshotPath('pages.json')), true);
        $homeHtml = null;
        foreach ($pages as $url => $info) {
            if (($info['code'] ?? null) !== 200 || ! str_starts_with($url, self::BASE)) {
                continue;
            }
            $path = substr($url, strlen(self::BASE)) ?: '/';
            if (str_starts_with($path, '/en') || in_array($path, self::SYSTEM_PATHS, true) || str_contains($path, '/feed') || str_contains($path, '/page/')) {
                continue;
            }
            if ($path === '/blog') {
                continue; // redirect către /blog/blog-sevalia-1
            }
            $html = file_get_contents($this->snapshotPath('pages/'.$info['file'].'.html'));
            if ($path === '/') {
                $homeHtml = $html;
            }
            if (str_starts_with($path, Page::BLOG_PATH.'/')) {
                $this->importArticle($path, $html);
            } elseif ($path === Page::BLOG_PATH) {
                $this->importBlogIndex($path, $html);
            } else {
                $this->importPage($path, $html);
            }
        }

        if ($homeHtml) {
            $this->importMenu($homeHtml);
            $this->importFooter($homeHtml);
        }
        $this->linkStaffPhotos();
        $this->seedRedirects();
        if (($logo = $this->mediaFor('/web/image/website/1/logo/sevalia.ro?unique=b2c6804')) && ! app(\App\Core\Settings\Settings::class)->get('site.logo_media_id')) {
            app(\App\Core\Settings\Settings::class)->set('site.logo_media_id', $logo->id);
        }

        return $this->report;
    }

    // ------------------------------------------------------------------ media

    private function importMedia(): void
    {
        $index = json_decode(file_get_contents($this->snapshotPath('images/index.json')), true);
        foreach ($index as $path => $info) {
            if (empty($info['file'])) {
                continue;
            }
            $file = $this->snapshotPath('images/'.$info['file']);
            if (! is_file($file)) {
                continue;
            }
            $media = $this->media->importFile($file, $path);
            $this->mediaByPath[$this->normalizeUrl($path)] = $media;
            $this->report['imagini']++;
        }
    }

    /** Documentele descărcabile (ex. PDF) legate din pagini prin /web/content/<id>. */
    private function importFiles(): void
    {
        $indexFile = $this->snapshotPath('files/index.json');
        if (! is_file($indexFile)) {
            return;
        }
        foreach (json_decode(file_get_contents($indexFile), true) as $path => $info) {
            $file = $this->snapshotPath('files/'.$info['file']);
            if (is_file($file)) {
                $media = $this->media->importFile($file, $path);
                if ($media->original_name !== $info['file']) {
                    $media->update(['original_name' => $info['file']]);
                }
                $this->mediaByPath[$this->normalizeUrl($path)] = $media;
                Redirect::firstOrCreate(['source_path' => $path], ['target' => $media->url(), 'status_code' => 301, 'note' => 'Document mutat din Odoo în biblioteca media']);
            }
        }
    }

    private function loadMediaMap(): void
    {
        // Mai multe adrese Odoo pot indica același fișier (același SHA-256): le mapăm pe toate.
        $bySha = Media::all()->keyBy('sha256');
        $index = json_decode(file_get_contents($this->snapshotPath('images/index.json')), true);
        foreach ($index as $path => $info) {
            if (! empty($info['sha256']) && isset($bySha[$info['sha256']])) {
                $this->mediaByPath[$this->normalizeUrl($path)] = $bySha[$info['sha256']];
            }
        }
    }

    private function normalizeUrl(string $url): string
    {
        $url = html_entity_decode(trim($url, " \t\n\"'"));
        if (str_starts_with($url, self::BASE)) {
            $url = substr($url, strlen(self::BASE));
        }

        return rawurldecode($url);
    }

    private function mediaFor(string $url): ?Media
    {
        $key = $this->normalizeUrl($url);
        if (isset($this->mediaByPath[$key])) {
            return $this->mediaByPath[$key];
        }
        // aceeași imagine fără parametrul ?unique / ?access_token
        $noQuery = strtok($key, '?');
        foreach ($this->mediaByPath as $k => $m) {
            if (strtok($k, '?') === $noQuery) {
                return $m;
            }
        }

        return null;
    }

    /** Înlocuiește adresele imaginilor Odoo cu cele din biblioteca media. */
    private function rewriteMedia(DOMElement $root): void
    {
        $xp = new DOMXPath($root->ownerDocument);
        foreach ($xp->query('.//img[@src]', $root) as $img) {
            /** @var DOMElement $img */
            $media = $this->mediaFor($img->getAttribute('src'));
            if ($media) {
                $img->setAttribute('src', $media->url());
                $img->setAttribute('data-cms-media', (string) $media->id);
                if (! $img->hasAttribute('alt')) {
                    $img->setAttribute('alt', '');
                }
            } elseif (! str_starts_with($img->getAttribute('src'), 'data:')) {
                $this->report['imagini_lipsa'][] = $img->getAttribute('src');
            }
        }
        foreach ($xp->query('.//a[starts-with(@href, "/web/content/")]', $root) as $a) {
            /** @var DOMElement $a */
            $media = $this->mediaFor(strtok($a->getAttribute('href'), '?'));
            if ($media) {
                $a->setAttribute('href', $media->url());
            }
        }
        foreach ($xp->query('.//*[contains(@style, "url(")]', $root) as $el) {
            /** @var DOMElement $el */
            $style = $el->getAttribute('style');
            $style = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) use ($el) {
                $media = $this->mediaFor($m[2]);
                if ($media) {
                    $el->setAttribute('data-cms-media-bg', (string) $media->id);

                    return 'url("'.$media->url().'")';
                }
                if (! str_starts_with($m[2], 'data:')) {
                    $this->report['imagini_lipsa'][] = $m[2];
                }

                return $m[0];
            }, html_entity_decode($style));
            $el->setAttribute('style', $style);
        }
    }

    // ------------------------------------------------------------------ pagini

    /** @return array{0: \DOMDocument, 1: DOMXPath} */
    private function document(string $html): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return [$doc, new DOMXPath($doc)];
    }

    private function seo(DOMXPath $xp): array
    {
        $meta = fn (string $q) => ($n = $xp->query($q)->item(0)) instanceof DOMElement ? html_entity_decode($n->getAttribute('content')) : null;
        $title = ($t = $xp->query('//title')->item(0)) ? trim(html_entity_decode($t->textContent)) : null;
        $og = $meta('//meta[@property="og:image"]');
        $ogMedia = $og ? $this->mediaFor($og) : null;

        return array_filter([
            'title' => $title,
            'description' => $meta('//meta[@name="description"]'),
            'keywords' => $meta('//meta[@name="keywords"]'),
            'og_title' => $meta('//meta[@property="og:title"]'),
            'og_image_media_id' => $ogMedia?->id,
            'noindex' => false,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function bodyClass(DOMXPath $xp): string
    {
        $wrap = $xp->query('//div[@id="wrapwrap"]')->item(0);

        return $wrap instanceof DOMElement ? trim(preg_replace('/\s+/', ' ', $wrap->getAttribute('class'))) : '';
    }

    /** Elementele de nivel superior din #wrap (secțiunile paginii). @return list<DOMElement> */
    private function topElements(DOMElement $container): array
    {
        $out = [];
        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $class = ' '.$child->getAttribute('class').' ';
            if ($child->tagName === 'div' && str_contains($class, ' oe_structure') && ! $child->getAttribute('data-snippet')) {
                array_push($out, ...$this->topElements($child));

                continue;
            }
            $out[] = $child;
        }

        return $out;
    }

    private function makeBlock(DOMElement $el, string $seed, int $index): array
    {
        $html = $el->ownerDocument->saveHTML($el);
        [$doc, $root] = Dom::load($html);
        StructureSanitizer::clean($root);
        $this->rewriteMedia($root);
        $clean = Dom::inner($root);

        $first = $root->firstElementChild;
        $snippet = $first?->getAttribute('data-snippet') ?: null;
        $class = $first?->getAttribute('class') ?? '';
        $label = $first?->getAttribute('data-name') ?: ($snippet ?: $first?->tagName);

        $type = match (true) {
            // Formularul poate fi și în interiorul unei alte secțiuni (ex. coloana din /contactus).
            str_contains(' '.$class.' ', ' s_website_form ') || str_contains($clean, 's_website_form_field') => 'form',
            str_contains(' '.$class.' ', ' s_company_team ') => 'team',
            str_contains(' '.$class.' ', ' s_blog_posts ') || $snippet === 's_blog_posts' => 'blog_latest',
            default => 'html',
        };

        $uid = 'b'.substr(sha1($seed.'|'.$index), 0, 10);
        $block = [
            'uid' => $uid,
            'type' => $type,
            'label' => Str::limit((string) $label, 80, ''),
            'snippet' => $snippet,
            'hidden' => false,
            'html' => Slots::ensureRepeatIds(Slots::annotate($clean), $uid),
        ];
        if ($type === 'form') {
            $block['config'] = ['form' => str_contains($seed, 'emsella') ? 'emsella' : 'contact'];
        }
        if ($type === 'blog_latest') {
            $block['config'] = ['count' => 3];
        }
        if ($type === 'html' && $snippet && ! isset($this->templatesSeen[$snippet.'|'.$label])) {
            $this->templatesSeen[$snippet.'|'.$label] = $block;
        }

        return $block;
    }

    private function importPage(string $path, string $html): void
    {
        [$doc, $xp] = $this->document($html);
        $wrap = $xp->query('//main//div[@id="wrap"]')->item(0) ?? $xp->query('//main')->item(0);
        if (! $wrap instanceof DOMElement) {
            $this->report['atentionari'][] = "$path: lipsește #wrap";

            return;
        }
        $blocks = [];
        foreach ($this->topElements($wrap) as $i => $el) {
            $blocks[] = $this->makeBlock($el, $path, $i);
        }
        $seo = $this->seo($xp);
        $h1 = $xp->query('//main//h1')->item(0);
        $title = $h1 ? trim(str_replace(["\u{200B}", "\u{FEFF}"], '', Dom::text($h1))) : '';
        if ($title === '') {
            $title = trim(preg_replace('/\s*\|\s*sevalia\.ro$/i', '', $seo['title'] ?? $path));
        }
        $title = Str::limit($title, 180, '');
        $wrapClass = $wrap->getAttribute('id') === 'wrap' ? trim($wrap->getAttribute('class')) : '';
        $this->store($path, Page::KIND_PAGE, $title ?: $path, $blocks, $seo, ['body_class' => $this->bodyClass($xp), 'wrap_class' => $wrapClass]);
    }

    private function importBlogIndex(string $path, string $html): void
    {
        [$doc, $xp] = $this->document($html);
        $top = $xp->query('//div[@id="o_wblog_blog_top"]')->item(0);
        $blocks = [];
        if ($top instanceof DOMElement) {
            $blocks[] = $this->makeBlock($top, $path, 0);
        }
        $blocks[] = ['uid' => 'b'.substr(sha1($path.'|index'), 0, 10), 'type' => 'blog_index', 'label' => 'Lista articolelor', 'snippet' => null, 'hidden' => false, 'config' => ['per_page' => 12]];
        $this->store($path, Page::KIND_PAGE, 'Blog Sevalia', $blocks, $this->seo($xp), ['body_class' => $this->bodyClass($xp), 'wrap_class' => 'js_blog website_blog']);
    }

    private function importArticle(string $path, string $html): void
    {
        [$doc, $xp] = $this->document($html);
        $title = ($n = $xp->query('//h1[contains(@class,"o_wblog_post_name")]')->item(0)) ? Dom::text($n) : $path;
        $subtitle = ($n = $xp->query('//div[contains(@class,"o_wblog_post_subtitle")]')->item(0)) ? Dom::text($n) : '';
        $coverEl = $xp->query('//section[@id="o_wblog_post_top"]//div[contains(@class,"o_record_cover_image")]')->item(0);
        $cover = null;
        if ($coverEl instanceof DOMElement && preg_match('/url\(\s*([\'"]?)(.*?)\1\s*\)/', html_entity_decode($coverEl->getAttribute('style')), $m)) {
            $cover = $this->mediaFor($m[2]);
        }
        $filterEl = $xp->query('//section[@id="o_wblog_post_top"]//div[contains(@class,"o_record_cover_filter")]')->item(0);
        $coverContainer = $xp->query('//section[@id="o_wblog_post_top"]//div[contains(@class,"o_record_cover_container")]')->item(0);
        $info = $xp->query('//div[@id="o_wblog_post_info"]')->item(0);
        $date = $info ? $this->parseRomanianDate(Dom::text($xp->query('.//span', $info)->item(0) ?? $info)) : null;
        $author = $info && ($a = $xp->query('.//div[@style="line-height:1"]/span', $info)->item(0)) ? Dom::text($a) : null;

        $content = $xp->query('//div[contains(@class,"o_wblog_post_content_field")]')->item(0);
        $blocks = [];
        if ($content instanceof DOMElement) {
            $buffer = '';
            $i = 0;
            $flush = function () use (&$buffer, &$blocks, &$i, $path) {
                if (trim(strip_tags($buffer, '<img>')) === '') {
                    $buffer = '';

                    return;
                }
                [$d, $r] = Dom::load($buffer);
                StructureSanitizer::clean($r);
                $this->rewriteMedia($r);
                $blocks[] = ['uid' => 'b'.substr(sha1($path.'|r'.$i++), 0, 10), 'type' => 'rich', 'label' => 'Text articol', 'snippet' => null, 'hidden' => false,
                    'html' => \App\Modules\Cms\Services\Html\InlinePurifier::rich(Dom::inner($r))];
                $buffer = '';
            };
            foreach ($content->childNodes as $child) {
                if ($child instanceof DOMElement && ($child->tagName === 'section' || $child->getAttribute('data-snippet'))) {
                    $flush();
                    $blocks[] = $this->makeBlock($child, $path, 100 + $i++);
                } else {
                    $buffer .= $doc->saveHTML($child);
                }
            }
            $flush();
        }

        $seo = $this->seo($xp);
        $this->store($path, Page::KIND_ARTICLE, $title, $blocks, $seo, [
            'body_class' => $this->bodyClass($xp),
            'subtitle' => $subtitle,
            'cover_class' => $coverContainer instanceof DOMElement ? trim(preg_replace('/\s+/', ' ', $coverContainer->getAttribute('class'))) : null,
            'cover_filter_style' => $filterEl instanceof DOMElement ? $filterEl->getAttribute('style') : null,
        ], [
            'published_at' => $date,
            'author_name' => $author,
            'cover_media_id' => $cover?->id,
        ]);
    }

    private function parseRomanianDate(string $text): ?Carbon
    {
        $months = ['ianuarie' => 1, 'februarie' => 2, 'martie' => 3, 'aprilie' => 4, 'mai' => 5, 'iunie' => 6, 'iulie' => 7, 'august' => 8,
            'septembrie' => 9, 'octombrie' => 10, 'noiembrie' => 11, 'decembrie' => 12,
            'ian' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'iun' => 6, 'iul' => 7, 'aug' => 8, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match('/(\d{1,2})\s+([a-zăâîșț]+)\.?\s+(\d{4})/u', mb_strtolower($text), $m) && isset($months[$m[2]])) {
            return Carbon::create((int) $m[3], $months[$m[2]], (int) $m[1], 9, 0, 0, config('app.timezone'));
        }

        return null;
    }

    /**
     * Creează sau actualizează pagina, numai dacă nu a fost editată din CMS după importul anterior.
     */
    private function store(string $path, string $kind, string $title, array $blocks, array $seo, array $settings, array $extra = []): void
    {
        $hash = hash('sha256', json_encode([$blocks, $seo, $settings, $title, $extra], JSON_UNESCAPED_UNICODE));
        $page = Page::where('path', $path)->first();

        if ($page) {
            $current = $page->currentVersion;
            $currentHash = $current ? hash('sha256', json_encode([$current->blocks, $current->seo ?? [], $current->settings ?? [], $current->title, $this->extraOf($page)], JSON_UNESCAPED_UNICODE)) : null;
            if ($page->import_hash !== null && $currentHash !== $page->import_hash) {
                $this->report['pagini_editate_sarite'][] = $path;

                return;
            }
            if ($page->import_hash === $hash) {
                $this->report['pagini_neschimbate'][] = $path;

                return;
            }
        }

        DB::transaction(function () use ($page, $path, $kind, $title, $blocks, $seo, $settings, $extra, $hash) {
            $created = ! $page;
            $page ??= Page::create(['path' => $path, 'kind' => $kind, 'title' => $title, 'status' => 'draft']);
            $page->forceFill($extra + ['kind' => $kind, 'imported_at' => now()])->save();
            $version = $this->pages->saveVersion($page, $blocks, $seo, $settings, null, null, 'Import din siteul original', $title);
            $this->pages->publish($page, $version, null);
            $page->forceFill(['import_hash' => $hash])->save();
            $this->report[$created ? 'pagini_create' : 'pagini_actualizate'][] = $path;
        });
    }

    private function extraOf(Page $page): array
    {
        return $page->kind === Page::KIND_ARTICLE ? [
            'published_at' => $page->published_at,
            'author_name' => $page->author_name,
            'cover_media_id' => $page->cover_media_id,
        ] : [];
    }

    // ------------------------------------------------------------------ meniu și subsol

    private function importMenu(string $homeHtml): void
    {
        if (MenuItem::where('menu', 'header')->exists()) {
            return; // meniul se administrează din CMS după primul import
        }
        [$doc, $xp] = $this->document($homeHtml);
        $ul = $xp->query('//header//ul[@id="top_menu"]')->item(0);
        if (! $ul instanceof DOMElement) {
            $this->report['atentionari'][] = 'Meniul principal nu a fost găsit.';

            return;
        }
        $sort = 0;
        foreach ($xp->query('./li', $ul) as $li) {
            $a = $xp->query('./a', $li)->item(0);
            if (! $a instanceof DOMElement) {
                continue;
            }
            $label = Dom::text($a);
            $mega = $xp->query('.//div[contains(@class,"o_mega_menu")]/section', $li)->item(0);
            $item = ['menu' => 'header', 'label' => $label, 'sort' => ++$sort, 'is_visible' => true];
            if ($mega instanceof DOMElement) {
                $block = $this->makeBlock($mega, 'menu|'.$label, 0);
                $block['type'] = 'html';
                $item += ['kind' => 'mega', 'url' => null, 'mega_block' => $block];
            } else {
                $item += ['kind' => 'link', 'url' => $a->getAttribute('href'), 'new_tab' => $a->getAttribute('target') === '_blank'];
            }
            MenuItem::create($item);
        }
    }

    private function importFooter(string $homeHtml): void
    {
        [$doc, $xp] = $this->document($homeHtml);
        $footer = $xp->query('//footer[@id="bottom"]/div[@id="footer"]')->item(0);
        if (! $footer instanceof DOMElement) {
            return;
        }
        $blocks = [];
        foreach ($this->topElements($footer) as $i => $el) {
            $blocks[] = $this->makeBlock($el, Page::FOOTER_PATH, $i);
        }
        // Decizia clinicii (2026-10-25): „Politica de cookie” deschide setările de cookie.
        foreach ($blocks as &$block) {
            $block['html'] = preg_replace('~<p([^>]*)>Politica de cookie</p>~u', '<p$1><a href="#setari-cookie" data-cookie-settings="1">Politica de cookie</a></p>', $block['html']);
        }
        unset($block);
        $this->store(Page::FOOTER_PATH, Page::KIND_GLOBAL, 'Subsol (toate paginile)', $blocks, [], []);
    }

    // ------------------------------------------------------------------ date structurate

    /** Leagă fotografiile medicilor de pe /echipa-noastra de fișele din modulul Clinică. */
    private function linkStaffPhotos(): void
    {
        $page = Page::where('path', '/echipa-noastra')->first();
        if (! $page?->currentVersion) {
            return;
        }
        foreach ($page->currentVersion->blocks as $block) {
            if ($block['type'] !== 'team') {
                continue;
            }
            [$doc, $root] = Dom::load($block['html']);
            $xp = new DOMXPath($doc);
            foreach ($xp->query('.//*[contains(@class,"o_grid_item")]', $root) as $item) {
                $name = ($h = $xp->query('.//h4', $item)->item(0)) ? Dom::text($h) : null;
                $img = $xp->query('.//img[@data-cms-media]', $item)->item(0);
                if (! $name || ! $img instanceof DOMElement) {
                    continue;
                }
                $staff = TeamMatcher::find($name);
                if ($staff && ! $staff->photo_media_id) {
                    $staff->forceFill(['photo_media_id' => (int) $img->getAttribute('data-cms-media')])->save();
                }
            }
        }
    }

    private function seedRedirects(): void
    {
        $rules = [
            ['/blog', Page::BLOG_PATH, 'Redirect existent în Odoo'],
            ['/website/info', '/', 'Pagină tehnică Odoo (lista modulelor instalate), fără conținut al clinicii'],
        ];
        foreach ($rules as [$from, $to, $note]) {
            Redirect::firstOrCreate(['source_path' => $from], ['target' => $to, 'status_code' => 301, 'note' => $note]);
        }
    }

    public function saveTemplates(): int
    {
        $n = 0;
        foreach ($this->templatesSeen as $key => $block) {
            [$snippet, $label] = explode('|', $key, 2);
            SectionTemplate::firstOrCreate(['snippet' => $snippet, 'name' => $label ?: $snippet], ['block' => $block]);
            $n++;
        }

        return $n;
    }
}
