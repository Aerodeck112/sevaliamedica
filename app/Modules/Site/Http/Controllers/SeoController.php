<?php

namespace App\Modules\Site\Http\Controllers;

use App\Core\Contracts\PublicBooking;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Page;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    /** Crawlerii care adună date pentru antrenarea modelelor AI. */
    public const AI_TRAINING_BOTS = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent', 'Bytespider', 'cohere-training-data-crawler'];

    /** Crawlerii prin care asistenții AI caută și citează pagini în răspunsuri. */
    public const AI_SEARCH_BOTS = ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User'];

    private const PRIVATE_PATHS = ['/panou', '/formular', '/tableta'];

    public function robots(Settings $settings): Response
    {
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $lines = ['User-agent: *'];
        // Mediile de test nu trebuie indexate.
        if (config('sevalia.stage') !== 'production') {
            $lines[] = 'Disallow: /';
        } else {
            foreach (self::PRIVATE_PATHS as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = 'Allow: /';
            // Accesul asistenților AI (Marketing → Google și AI): implicit permis, pentru vizibilitate.
            $mode = (string) $settings->get('marketing.ai_crawlers', 'permite');
            $blocked = match ($mode) {
                'blocheaza' => [...self::AI_TRAINING_BOTS, ...self::AI_SEARCH_BOTS],
                'doar_cautare' => self::AI_TRAINING_BOTS,
                default => [],
            };
            if ($blocked) {
                $lines[] = '';
                foreach ($blocked as $bot) {
                    $lines[] = 'User-agent: '.$bot;
                }
                $lines[] = 'Disallow: /';
            }
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$base.'/sitemap.xml';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(): Response
    {
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $pages = Page::published()->whereIn('kind', [Page::KIND_PAGE, Page::KIND_ARTICLE])->with('publishedVersion')->orderBy('path')->get()
            ->reject(fn (Page $p) => (bool) ($p->publishedVersion?->seo['noindex'] ?? false) || $p->path === '/contactus-thank-you');

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($pages as $page) {
            $xml[] = '  <url><loc>'.e($base.($page->path === '/' ? '/' : $page->path)).'</loc><lastmod>'.$page->updated_at?->toDateString().'</lastmod></url>';
        }
        if (app(PublicBooking::class)->available()) {
            $xml[] = '  <url><loc>'.e($base.'/programare-online').'</loc></url>';
        }
        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
