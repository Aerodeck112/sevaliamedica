<?php

namespace App\Modules\Marketing\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Marketing\Models\SeoAuditPage;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Site\Services\SiteView;
use Illuminate\Http\Request;

/**
 * Corectorul SEO: pentru problemele din ultimul audit care au o rezolvare sigură, propune valoarea nouă, pe care
 * omul o poate modifica înainte de aplicare. Nu inventează nimic: titlurile se scurtează sau se completează cu
 * numele clinicii și localitatea, descrierile se compun din fraze existente pe pagină.
 *
 * Problemele care cer conținut nou (text puțin pe o pagină de serviciu) sau date ale clinicii (Search Console,
 * Google Business) devin sarcini, cu link direct; nu se rezolvă automat.
 */
class SeoFixer
{
    public const TITLE_MIN = 30;

    public const TITLE_MAX = 65;

    public const DESC_MIN = 70;

    public const DESC_MAX = 160;

    /** Pagini de tip formular, contact, condiții: textul minim al paginilor de serviciu nu li se aplică. */
    private const INFO_PATHS = '~^/(contactus|locatii|tarife|termeni|politica|cum-ajung|.*thank-you|.*landing|.*oferta)~';

    /** Pagini care nu trebuie să apară în Google (confirmări după formular). */
    private const NOINDEX_PATHS = '~(thank-you|multumim|confirmare)~';

    public function __construct(private readonly SiteView $view, private readonly PageService $pages) {}

    /**
     * @return array{fixes: list<array{page_id: int, path: string, field: string, code: string, label: string, current: string, proposed: string, blocked: ?string}>,
     *               tasks: list<array{path: ?string, code: string, message: string, action: string, url: ?string}>}
     */
    public function proposals(SeoAuditRun $run): array
    {
        $fixes = [];
        $tasks = [];
        $rows = SeoAuditPage::where('seo_audit_run_id', $run->id)->whereNotNull('page_id')->orderBy('path')->get();
        $pages = Page::with(['publishedVersion'])->whereIn('id', $rows->pluck('page_id'))->get()->keyBy('id');

        foreach ($rows as $row) {
            $page = $pages->get($row->page_id);
            $version = $page?->publishedVersion;
            if (! $page || ! $version) {
                continue;
            }
            $codes = collect($row->issues ?? [])->pluck('code')->all();
            $seo = (array) $version->seo;
            $m = (array) $row->metrics;
            $blocked = $page->current_version_id !== $page->published_version_id
                ? 'Pagina are o ciornă nepublicată: corectați din editor, ca să nu se piardă ciorna.' : null;
            $add = function (string $field, string $code, string $label, string $current, string $proposed) use (&$fixes, $page, $blocked) {
                if ($proposed !== '' && $proposed !== $current) {
                    $fixes[] = compact('field', 'code', 'label', 'current', 'proposed', 'blocked') + ['page_id' => $page->id, 'path' => $page->path];
                }
            };

            if (preg_match(self::NOINDEX_PATHS, $page->path) && empty($seo['noindex']) && array_intersect($codes, ['h1_missing', 'desc_missing', 'title_length', 'thin_content'])) {
                $add('noindex', 'noindex', 'Exclude din Google (pagină de confirmare după formular)', 'indexată', 'exclusă');

                continue;
            }
            if (array_intersect($codes, ['title_missing', 'title_length'])) {
                $add('title', 'title_length', 'Titlu în Google', (string) ($m['title'] ?? ''), self::fitTitle((string) ($m['title'] ?? ''), $page));
            }
            if (array_intersect($codes, ['desc_missing', 'desc_length'])) {
                $text = $this->pageText($page);
                $add('description', in_array('desc_missing', $codes, true) ? 'desc_missing' : 'desc_length', 'Descriere în Google',
                    (string) ($m['description'] ?? ''), self::fitDescription((string) ($m['description'] ?? ''), $text, $page));
            }
            if (in_array('thin_content', $codes, true)) {
                if (preg_match(self::INFO_PATHS, $page->path) && ($seo['page_role'] ?? null) !== 'info') {
                    $add('page_role', 'thin_content', 'Tip pagină: informativă (contact, locații, condiții) — fără cerința de text minim', 'serviciu', 'informativă');
                } else {
                    $tasks[] = ['path' => $page->path, 'code' => 'thin_content', 'message' => sprintf('Text puțin (%d cuvinte). Paginile de servicii au nevoie de cel puțin 250 de cuvinte: indicații, cum decurge ședința, întrebări frecvente — scrise sau validate de specialist.', (int) ($m['words'] ?? 0)),
                        'action' => 'Scrie text în editor', 'url' => route('panou.cms.pages.edit', $page)];
                }
            }
            foreach ($row->issues ?? [] as $issue) {
                if (in_array($issue['code'], ['h1_missing', 'h1_multiple', 'link_broken', 'legacy_reference', 'img_alt_missing', 'jsonld_invalid', 'title_duplicate', 'desc_duplicate'], true)
                    && ! ($issue['code'] === 'h1_missing' && preg_match(self::NOINDEX_PATHS, $page->path))) {
                    $tasks[] = ['path' => $page->path, 'code' => $issue['code'], 'message' => $issue['message'], 'action' => 'Deschide în editor', 'url' => route('panou.cms.pages.edit', $page)];
                }
            }
        }

        foreach ((array) ($run->site_checks['site'] ?? []) as $check) {
            if ($check['severity'] === 'info' || $check['code'] === 'robots_blocked') {
                continue;
            }
            $tasks[] = ['path' => null, 'code' => $check['code'], 'message' => $check['message'], 'action' => 'Completează în Google și AI',
                'url' => route('panou.marketing.integrations')];
        }

        return ['fixes' => $fixes, 'tasks' => $tasks];
    }

    /**
     * Aplică valorile alese: o versiune nouă publicată pe pagină (istoricul păstrează versiunea anterioară).
     *
     * @param  array<int, array<string, string>>  $values  page_id => [field => valoare]
     * @return array{applied: list<string>, skipped: array<string, string>}
     */
    public function apply(array $values, User $user): array
    {
        $applied = [];
        $skipped = [];
        foreach (Page::with('publishedVersion')->whereIn('id', array_keys($values))->get() as $page) {
            $version = $page->publishedVersion;
            if (! $version) {
                continue;
            }
            if ($page->current_version_id !== $page->published_version_id) {
                $skipped[$page->path] = 'are o ciornă nepublicată';

                continue;
            }
            $seo = (array) $version->seo;
            $before = $seo;
            foreach ($values[$page->id] as $field => $value) {
                $value = trim((string) $value);
                match ($field) {
                    'title' => $value !== '' ? ($seo['title'] = $seo['og_title'] = mb_substr($value, 0, 120)) : null,
                    'description' => $value !== '' ? ($seo['description'] = mb_substr($value, 0, 300)) : null,
                    'noindex' => $seo['noindex'] = true,
                    'page_role' => $seo['page_role'] = 'info',
                    default => null,
                };
            }
            if ($seo == $before) {
                continue;
            }
            $new = $this->pages->saveVersion($page, $version->blocks, $seo, (array) $version->settings, $user, $page->lock_version, 'Corector SEO');
            $this->pages->publish($page->fresh(), $new, $user);
            Audit::log('marketing.seo_fix', $page, ['campuri' => array_keys($values[$page->id])], userId: $user->id);
            $applied[] = $page->path;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------ propuneri

    /** Titlul încadrat în 30–65 de caractere, fără a schimba sensul. */
    public static function fitTitle(string $title, Page $page): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            $title = $page->title;
        }
        $len = fn (string $s) => mb_strlen($s);
        if ($page->path === Page::BLOG_PATH) {
            return 'Blog Sevalia – sfaturi de sănătate și recuperare';
        }
        $brand = $page->kind === Page::KIND_ARTICLE ? ' | Blog Sevalia' : ' | SEVALIA';
        if ($len($title) > self::TITLE_MAX) {
            // 1. Fără sufixul de brand / domeniu.
            $short = trim((string) preg_replace('/\s*[|–-]\s*(sevalia\.ro|SEVALIA|Sevalia|Blog Sevalia)\s*$/u', '', $title));
            if ($len($short) <= self::TITLE_MAX && $len($short) >= self::TITLE_MIN) {
                return $short;
            }
            // 2. Până la ultimul separator care încape.
            foreach ([' | ', ' – ', ' - ', ': ', ' — '] as $sep) {
                $parts = explode($sep, $short);
                while (count($parts) > 1) {
                    array_pop($parts);
                    $candidate = implode($sep, $parts);
                    if ($len($candidate) < self::TITLE_MIN) {
                        // Partea întreagă e scurtă: se completează cu numele clinicii, nu se taie fraza la jumătate.
                        $candidate .= $brand;
                    }
                    if ($len($candidate) <= self::TITLE_MAX && $len($candidate) >= self::TITLE_MIN) {
                        return $candidate;
                    }
                }
            }
            // 3. La ultimul cuvânt întreg care încape.
            $cut = mb_substr($short, 0, self::TITLE_MAX);
            $cut = (string) preg_replace('/\s+\S*$/u', '', $cut);

            return rtrim($cut, " ,;:–-|");
        }
        if ($len($title) < self::TITLE_MIN) {
            $base = trim((string) preg_replace('/\s*[|–-]\s*(sevalia\.ro|SEVALIA|Sevalia)\s*$/u', '', $title));
            foreach ([' | SEVALIA Târgu Mureș', ' – Sevalia Târgu Mureș', ' | SEVALIA'] as $suffix) {
                $candidate = $base.$suffix;
                if ($len($candidate) >= self::TITLE_MIN && $len($candidate) <= self::TITLE_MAX) {
                    return $candidate;
                }
            }
        }

        return $title;
    }

    /** Descrierea încadrată în 70–160 de caractere, din fraze existente (descrierea actuală, apoi textul paginii). */
    public static function fitDescription(string $current, string $pageText, Page $page): string
    {
        $clean = fn (string $s) => trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{200B}", '', $s)));
        $current = $clean($current);
        $len = fn (string $s) => mb_strlen($s);
        if ($page->path === Page::BLOG_PATH || str_starts_with($page->path, Page::BLOG_PATH) && substr_count($page->path, '/') <= 2) {
            return 'Articole despre recuperare medicală, reumatologie, kinetoterapie și sănătate, de la specialiștii Sevalia din Târgu Mureș.';
        }
        $sentences = [];
        foreach ([$current, $clean($pageText)] as $source) {
            foreach (preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', $source) ?: [] as $s) {
                $s = $clean($s);
                if ($len($s) >= 25 && ! in_array($s, $sentences, true)) {
                    $sentences[] = $s;
                }
            }
        }
        $out = '';
        foreach ($sentences as $s) {
            $candidate = $out === '' ? $s : $out.' '.$s;
            if ($len($candidate) <= self::DESC_MAX) {
                $out = $candidate;
                if ($len($out) >= 120) {
                    break;
                }
            } elseif ($out === '') {
                // Prima frază e prea lungă: se taie la ultimul cuvânt întreg.
                $cut = (string) preg_replace('/\s+\S*$/u', '', mb_substr($s, 0, self::DESC_MAX - 1));
                $out = rtrim($cut, " ,;:–-").'…';
                break;
            }
        }

        return $len($out) >= self::DESC_MIN ? $out : $current;
    }

    /** Textul vizibil al conținutului paginii (fără meniu și subsol). */
    private function pageText(Page $page): string
    {
        try {
            if (! app()->bound('csp-nonce')) {
                app()->instance('csp-nonce', 'audit');
            }
            $html = $this->view->page($page, $page->publishedVersion, Request::create($page->path))->render();
        } catch (\Throwable) {
            return '';
        }
        if (! preg_match('~<main\b[^>]*>(.*)</main>~su', $html, $m)) {
            return '';
        }
        $main = (string) preg_replace('~<(script|style|nav|form|h[1-6])\b.*?</\1>~su', ' ', $m[1]);

        return html_entity_decode(strip_tags((string) preg_replace('~<(p|li|div|br)\b~i', ' <$1', $main)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
