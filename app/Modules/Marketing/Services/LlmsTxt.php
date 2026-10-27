<?php

namespace App\Modules\Marketing\Services;

use App\Core\Settings\Settings;
use App\Modules\Clinic\Models\Location;
use App\Modules\Cms\Models\Page;

/**
 * Fișierul /llms.txt (propunerea https://llmstxt.org): un rezumat în Markdown al siteului pentru asistenții AI
 * (ChatGPT, Claude, Perplexity…). Conține numai informații deja publice pe site: paginile publicate, descrierile lor
 * SEO și datele de contact ale locațiilor. Este o convenție, nu un standard: nu garantează citarea în răspunsuri.
 */
class LlmsTxt
{
    private const DAYS = [1 => 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă', 'duminică'];

    public function __construct(private readonly Settings $settings) {}

    public function render(): string
    {
        $base = SeoAuditService::base();
        $pages = SeoAuditService::auditablePages()->with('publishedVersion')->get()
            ->reject(fn (Page $p) => (bool) ($p->publishedVersion?->seo['noindex'] ?? false) || $p->path === '/contactus-thank-you');
        $home = $pages->firstWhere('path', '/');
        $summary = trim((string) $this->settings->get('marketing.llms_summary', ''))
            ?: (string) ($home?->publishedVersion?->seo['description'] ?? 'Clinică de recuperare medicală din Târgu Mureș.');

        $out = ['# Sevalia Medica', '', '> '.self::line($summary), ''];
        $notes = trim(strip_tags((string) $this->settings->get('marketing.llms_notes', '')));
        if ($notes !== '') {
            $out[] = $notes;
            $out[] = '';
        }

        $locations = Location::active()->get();
        if ($locations->isNotEmpty()) {
            $out[] = '## Locații și contact';
            $out[] = '';
            foreach ($locations as $loc) {
                $hours = [];
                foreach (self::DAYS as $n => $day) {
                    $slots = array_map(fn ($s) => $s[0].'–'.$s[1], $loc->hoursFor($n));
                    if ($slots) {
                        $hours[] = $day.' '.implode(', ', $slots);
                    }
                }
                $parts = array_filter([
                    trim(implode(', ', array_filter([$loc->address, $loc->city, $loc->county]))),
                    $loc->phone ? 'telefon '.$loc->phone : null,
                    $loc->email ? 'e-mail '.$loc->email : null,
                    $hours ? 'program: '.implode('; ', $hours) : null,
                ]);
                $out[] = '- '.self::line($loc->name).': '.self::line(implode(' · ', $parts));
            }
            $out[] = '';
        }

        $services = $pages->filter(fn (Page $p) => ! empty($p->publishedVersion?->seo['service_name']));
        $articles = $pages->where('kind', Page::KIND_ARTICLE)->sortByDesc('published_at')->take(40);
        $others = $pages->where('kind', Page::KIND_PAGE)->diffKeys($services)->reject(fn (Page $p) => $p->path === '/');
        foreach ([['Servicii', $services], ['Pagini', $others], ['Articole', $articles]] as [$heading, $list]) {
            if ($list->isEmpty()) {
                continue;
            }
            $out[] = '## '.$heading;
            $out[] = '';
            foreach ($list as $page) {
                $seo = $page->publishedVersion?->seo ?? [];
                // Numele: serviciul, altfel titlul SEO fără sufixul „| SEVALIA”, altfel titlul paginii.
                $seoTitle = trim((string) preg_replace('/\s*[|–-]\s*(SEVALIA|sevalia\.ro|Sevalia[^|]*)\s*$/iu', '', (string) ($seo['title'] ?? '')));
                $name = $seo['service_name'] ?? ($seoTitle !== '' ? $seoTitle : $page->title);
                $desc = $seo['description'] ?? null;
                $out[] = '- ['.self::line($name).']('.$base.($page->path === '/' ? '/' : $page->path).')'.($desc ? ': '.self::line($desc) : '');
            }
            $out[] = '';
        }

        $out[] = '## Important';
        $out[] = '';
        $out[] = '- Informațiile de pe site sunt generale și nu înlocuiesc consultația medicală.';
        $out[] = '- Programările se fac telefonic sau prin formularul de pe site: '.$base.'/contactus';

        return implode("\n", $out)."\n";
    }

    private static function line(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']'], ['(', ')'], $text)));
    }
}
