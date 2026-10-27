<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Cms\Models\Page;
use App\Modules\Site\Services\PageCache;
use App\Modules\Site\Services\SiteView;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;

/**
 * Baza de cunoștințe a asistentului: numai conținutul public al siteului (paginile publicate și indexabile).
 * Pasajele sunt întrebările frecvente exact cum apar pe pagini, descrierile SEO și paragrafele de text, fiecare cu
 * pagina-sursă. Se reconstruiește automat la orice publicare (versiunea cache-ului paginilor).
 */
class KnowledgeBase
{
    /**
     * @return list<array{kind: string, title: string, text: string, path: string, page: string, tokens: list<string>, title_tokens: list<string>}>
     */
    public function passages(): array
    {
        return Cache::remember('assistant-kb:'.PageCache::version(), 86400, fn () => $this->build());
    }

    private function build(): array
    {
        $out = [];
        $pages = Page::published()->whereIn('kind', [Page::KIND_PAGE, Page::KIND_ARTICLE])->with('publishedVersion')->get();
        foreach ($pages as $page) {
            $version = $page->publishedVersion;
            $seo = (array) ($version?->seo ?? []);
            if (! $version || ! empty($seo['noindex']) || in_array($page->path, ['/contactus-thank-you', '/terms'], true)) {
                continue;
            }
            $name = $seo['service_name'] ?? self::pageName($page->title, $seo['title'] ?? null);
            $html = '';
            foreach ((array) $version->blocks as $block) {
                if (empty($block['hidden'])) {
                    $html .= $block['html'] ?? '';
                }
            }
            if (! empty($seo['description'])) {
                $out[] = $this->passage('descriere', $name, $seo['description'], $page->path, $name);
            }
            foreach (SiteView::faqFromHtml($html) as $faq) {
                $out[] = $this->passage('faq', $faq['name'], $faq['acceptedAnswer']['text'], $page->path, $name);
            }
            foreach ($this->paragraphs($html) as [$heading, $text]) {
                $out[] = $this->passage('text', $heading ?: $name, $text, $page->path, $name);
            }
        }

        return $out;
    }

    private function passage(string $kind, string $title, string $text, string $path, string $page): array
    {
        $text = self::clean($text);

        return [
            'kind' => $kind, 'title' => self::clean($title), 'text' => $text, 'path' => $path, 'page' => $page,
            'tokens' => Text::tokens($title.' '.$text), 'title_tokens' => Text::tokens($title.' '.$page),
        ];
    }

    /** Paragrafele consistente, grupate sub ultimul titlu întâlnit, în bucăți de cel mult ~600 de caractere. */
    private function paragraphs(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($doc);
        $out = [];
        $heading = null;
        $buffer = '';
        $flush = function () use (&$out, &$buffer, &$heading) {
            if (mb_strlen($buffer) >= 60) {
                $out[] = [$heading, $buffer];
            }
            $buffer = '';
        };
        // Întrebările frecvente sunt preluate separat.
        foreach ($xp->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::p or self::li][not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " accordion ")])]') as $node) {
            $text = self::clean($node->textContent);
            if ($text === '') {
                continue;
            }
            if (in_array($node->nodeName, ['h1', 'h2', 'h3'], true) && mb_strlen($text) < 120) {
                $flush();
                $heading = $text;

                continue;
            }
            if (mb_strlen($buffer) + mb_strlen($text) > 600) {
                $flush();
            }
            $buffer = trim($buffer.' '.$text);
        }
        $flush();

        return $out;
    }

    public static function pageName(string $title, ?string $seoTitle): string
    {
        $seo = trim((string) preg_replace('/\s*[|–-]\s*(SEVALIA|sevalia\.ro|Sevalia[^|]*)\s*$/iu', '', (string) $seoTitle));

        return $seo !== '' ? $seo : $title;
    }

    public static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{200B}"], [' ', ''], html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
