<?php

namespace App\Modules\Site\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Page;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function robots(): Response
    {
        $base = rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
        $lines = ['User-agent: *'];
        // Mediile de test nu trebuie indexate.
        if (config('sevalia.stage') !== 'production') {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /panou';
            $lines[] = 'Disallow: /formular';
            $lines[] = 'Allow: /';
        }
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
        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
