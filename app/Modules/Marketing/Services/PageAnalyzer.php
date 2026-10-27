<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Cms\Services\LinkChecker;
use DOMDocument;
use DOMXPath;

/**
 * Analiza SEO a HTML-ului randat al unei pagini: titlu, descriere, H1, canonical, limbă, texte alternative,
 * linkuri interne rupte, trimiteri la siteul vechi, date structurate, volum de text.
 * Nu modifică nimic; întoarce problemele găsite (cu gravitate) și un scor 0–100.
 */
class PageAnalyzer
{
    /** Penalizări pe tip de problemă (din 100). Valorile cu plafon se aplică pe apariție, până la plafon. */
    public const RULES = [
        'title_missing' => ['error', 20, 'Pagina nu are titlu (<title>).'],
        'title_length' => ['warn', 5, 'Titlul are %d caractere; recomandat 30–65, ca să apară întreg în Google.'],
        'title_duplicate' => ['warn', 8, 'Același titlu apare și pe: %s.'],
        'desc_missing' => ['error', 15, 'Lipsește descrierea (meta description) — Google va alege singur un fragment.'],
        'desc_auto' => ['info', 0, 'Descrierea este preluată automat din primul paragraf; una scrisă în CMS (Pagină → SEO), cu cuvântul cheie, convinge mai bine în Google.'],
        'desc_length' => ['warn', 5, 'Descrierea are %d caractere; recomandat 70–160.'],
        'desc_duplicate' => ['warn', 5, 'Aceeași descriere apare și pe: %s.'],
        'h1_missing' => ['error', 12, 'Pagina nu are un titlu principal H1.'],
        'h1_multiple' => ['warn', 4, 'Pagina are %d titluri H1; recomandat unul singur.'],
        'canonical_missing' => ['warn', 8, 'Lipsește adresa canonică (rel=canonical).'],
        'canonical_other' => ['info', 0, 'Adresa canonică trimite la altă pagină: %s.'],
        'lang_missing' => ['warn', 5, 'Elementul <html> nu declară limba.'],
        'noindex' => ['info', 0, 'Pagina este exclusă din indexare (noindex) — nu apare în Google și nici în sitemap.'],
        'img_alt_missing' => ['warn', 2, '%d imagini fără text alternativ (alt).', 10],
        'link_broken' => ['error', 5, 'Link intern către o pagină inexistentă: %s.', 20],
        'legacy_reference' => ['error', 10, 'Trimitere la siteul vechi sau la o cale Odoo: %s.', 20],
        'jsonld_invalid' => ['error', 10, 'Datele structurate (JSON-LD) nu sunt JSON valid.'],
        'thin_content' => ['warn', 5, 'Text puțin (%d cuvinte); paginile de servicii au nevoie de conținut consistent.'],
        'og_image_missing' => ['info', 0, 'Nu are imagine pentru distribuire (og:image) — rețelele sociale aleg una la întâmplare.'],
        'html_heavy' => ['warn', 3, 'HTML-ul are %d KB; peste 300 KB încetinește încărcarea pe mobil.'],
    ];

    public function __construct(private readonly LinkChecker $links) {}

    /**
     * @return array{issues: list<array{code: string, severity: string, message: string}>, metrics: array<string, mixed>, text: string}
     */
    public function analyze(string $html, string $path, string $canonicalBase, string $kind = 'page'): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($dom);

        $issues = [];
        $add = function (string $code, ...$args) use (&$issues) {
            $issues[] = ['code' => $code, 'severity' => self::RULES[$code][0], 'message' => vsprintf(self::RULES[$code][2], $args)];
        };

        $title = trim((string) $xp->evaluate('string(//head/title)'));
        $description = trim((string) $xp->evaluate('string(//head/meta[@name="description"]/@content)'));
        $canonical = trim((string) $xp->evaluate('string(//head/link[@rel="canonical"]/@href)'));
        $robots = strtolower((string) $xp->evaluate('string(//head/meta[@name="robots"]/@content)'));
        $lang = trim((string) $xp->evaluate('string(/html/@lang)'));
        $ogImage = trim((string) $xp->evaluate('string(//head/meta[@property="og:image"]/@content)'));
        $h1 = [];
        foreach ($xp->query('//body//h1') as $node) {
            $h1[] = self::clean($node->textContent);
        }

        $titleLen = mb_strlen($title);
        $descLen = mb_strlen($description);
        if ($title === '') {
            $add('title_missing');
        } elseif ($titleLen < 30 || $titleLen > 65) {
            $add('title_length', $titleLen);
        }
        if ($description === '') {
            $add('desc_missing');
        } elseif ($descLen < 70 || $descLen > 160) {
            $add('desc_length', $descLen);
        }
        if (count(array_filter($h1)) === 0) {
            $add('h1_missing');
        } elseif (count($h1) > 1) {
            $add('h1_multiple', count($h1));
        }
        $noindex = str_contains($robots, 'noindex');
        if ($noindex) {
            $add('noindex');
        } elseif ($canonical === '') {
            $add('canonical_missing');
        } elseif (rtrim($canonical, '/') !== rtrim($canonicalBase.($path === '/' ? '' : $path), '/')) {
            $add('canonical_other', $canonical);
        }
        if ($lang === '') {
            $add('lang_missing');
        }

        // Imaginile din conținut (fără cele din antet/subsol comune tuturor paginilor, verificate o singură dată).
        $images = $xp->query('//main//img');
        $noAlt = 0;
        foreach ($images as $img) {
            if (! $img->hasAttribute('alt')) {
                $noAlt++;
            }
        }
        if ($noAlt > 0) {
            $issues[] = ['code' => 'img_alt_missing', 'severity' => 'warn', 'message' => sprintf(self::RULES['img_alt_missing'][2], $noAlt), 'count' => $noAlt];
        }

        $mainHtml = '';
        foreach ($xp->query('//main') as $main) {
            $mainHtml .= $dom->saveHTML($main);
        }
        foreach (array_slice($this->links->brokenIn($mainHtml), 0, 10) as $broken) {
            $add('link_broken', $broken);
        }
        if (preg_match_all('#(?:https?://(?:www\.)?sevalia\.ro)?/web/(?:image|content)[^"\'\s)]*|https?://(?:www\.)?sevalia\.ro/[^"\'\s)]*#i', $html, $m)) {
            $canonicalHost = parse_url($canonicalBase, PHP_URL_HOST);
            foreach (array_slice(array_unique($m[0]), 0, 5) as $ref) {
                // Linkurile absolute către domeniul canonic (ex. canonical, og:url) sunt corecte după lansare.
                if (! str_contains($ref, '/web/') && $canonicalHost && parse_url($ref, PHP_URL_HOST) === $canonicalHost) {
                    continue;
                }
                $add('legacy_reference', $ref);
            }
        }

        $types = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true);
            if (! is_array($data)) {
                $add('jsonld_invalid');

                continue;
            }
            foreach (isset($data['@graph']) ? $data['@graph'] : [$data] as $node) {
                if (isset($node['@type'])) {
                    $types[] = $node['@type'];
                }
            }
        }

        $text = '';
        foreach ($xp->query('//main') as $main) {
            $text .= ' '.$main->textContent;
        }
        $text = self::clean($text);
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
        if ($kind === 'page' && ! $noindex && $words < 250 && $path !== '/contactus-thank-you') {
            $add('thin_content', $words);
        }
        if ($ogImage === '' && ! $noindex) {
            $add('og_image_missing');
        }
        $kb = (int) round(strlen($html) / 1024);
        if ($kb > 300) {
            $add('html_heavy', $kb);
        }

        return [
            'issues' => $issues,
            'metrics' => [
                'title' => $title, 'title_length' => $titleLen, 'description' => $description, 'description_length' => $descLen,
                'h1' => $h1[0] ?? null, 'h1_count' => count($h1), 'canonical' => $canonical, 'noindex' => $noindex,
                'images' => $images->length, 'images_without_alt' => $noAlt, 'words' => $words, 'html_kb' => $kb,
                'jsonld' => array_values(array_unique(array_map(fn ($t) => is_array($t) ? implode('/', $t) : $t, $types))),
            ],
            'text' => $text,
        ];
    }

    /** Scorul paginii: 100 minus penalizările, cu plafon pe tipurile repetabile. */
    public static function score(array $issues): int
    {
        $penalty = [];
        foreach ($issues as $issue) {
            [, $points] = self::RULES[$issue['code']];
            $cap = self::RULES[$issue['code']][3] ?? $points;
            $add = $issue['code'] === 'img_alt_missing' ? $points * (int) ($issue['count'] ?? 1) : $points;
            $penalty[$issue['code']] = min($cap, ($penalty[$issue['code']] ?? 0) + $add);
        }

        return max(0, 100 - array_sum($penalty));
    }

    public static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
