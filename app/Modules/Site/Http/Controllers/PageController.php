<?php

namespace App\Modules\Site\Http\Controllers;

use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Site\Services\PageCache;
use App\Modules\Site\Services\SiteView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class PageController extends Controller
{
    public const SOCIAL_DEFAULTS = [
        'facebook' => 'https://www.facebook.com/SevaliaMedica/',
        'youtube' => 'https://www.youtube.com/@sevaliamedica',
        'instagram' => 'https://www.instagram.com/sevalia.medica/',
        'tiktok' => 'https://www.tiktok.com/@sevaliamedica',
    ];

    public function __construct(private readonly SiteView $view, private readonly Settings $settings) {}

    public function show(Request $request): BaseResponse
    {
        $path = '/'.trim(rawurldecode($request->getPathInfo()), '/');

        if ($request->getPathInfo() !== '/' && str_ends_with($request->getPathInfo(), '/')) {
            return redirect($path.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }
        if ($redirect = $this->redirectFor($path)) {
            return $redirect;
        }

        $page = Page::published()->where('path', $path)->whereIn('kind', [Page::KIND_PAGE, Page::KIND_ARTICLE])->first();
        if (! $page) {
            return $this->notFound($request);
        }

        return $this->respond($request, $page, $page->publishedVersion);
    }

    public function blogPage(Request $request, int $number): BaseResponse
    {
        if ($number <= 1) {
            return redirect(Page::BLOG_PATH, 301);
        }
        $page = Page::published()->where('path', Page::BLOG_PATH)->first();
        if (! $page) {
            return $this->notFound($request);
        }

        return $this->respond($request, $page, $page->publishedVersion);
    }

    public function social(string $network): BaseResponse
    {
        $links = array_merge(self::SOCIAL_DEFAULTS, (array) $this->settings->get('site.social', []));
        abort_unless(! empty($links[$network]) && str_starts_with($links[$network], 'https://'), 404);

        return redirect()->away($links[$network], 303);
    }

    private function respond(Request $request, Page $page, PageVersion $version): BaseResponse
    {
        $cacheable = $request->query() === [] || array_keys($request->query()) === ['page'];
        $cacheKey = $request->getPathInfo();
        $entry = $cacheable ? PageCache::get($cacheKey) : null;
        if (! $entry) {
            $html = $this->view->page($page, $version, $request)->render();
            $entry = ['html' => $html, 'etag' => '"'.substr(sha1($html), 0, 20).'"', 'modified' => ($page->updated_at ?? now())->toRfc7231String()];
            if ($cacheable) {
                PageCache::put($cacheKey, $entry);
            }
        }

        return $this->html($request, $entry, 200, $cacheable);
    }

    private function html(Request $request, array $entry, int $status, bool $cacheable): BaseResponse
    {
        $response = new Response($entry['html'], $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        if ($cacheable && $status === 200) {
            $response->setEtag(trim($entry['etag'], '"'));
            $response->headers->set('Last-Modified', $entry['modified']);
            $response->setPublic();
            $response->setMaxAge(300);
            $response->isNotModified($request);
        } else {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function redirectFor(string $path): ?BaseResponse
    {
        $redirect = Redirect::where('source_path', $path)->where('is_active', true)->first();
        if ($redirect) {
            $redirect->newQuery()->whereKey($redirect->id)->update(['hits' => $redirect->hits + 1, 'last_hit_at' => now()]);

            return redirect($redirect->target, $redirect->status_code === 302 ? 302 : 301);
        }

        // Versiunea /en generată de Odoo: implicit 301 către echivalentul în română (configurabil din panou).
        if (($path === '/en' || str_starts_with($path, '/en/')) && $this->settings->get('site.en_redirect', true)) {
            return redirect($this->romanianEquivalent($path), 301);
        }

        return null;
    }

    public static function romanianEquivalent(string $path): string
    {
        $rest = substr($path, 3) ?: '/';
        if (preg_match('#^/blog/[^/]+/(?:.*-)?(\d+)$#', $rest, $m)) {
            $article = Page::articles()->where('path', 'like', '%-'.$m[1])->first();
            if ($article) {
                return $article->path;
            }
        }
        if (str_starts_with($rest, '/blog')) {
            return Page::BLOG_PATH;
        }

        return $rest;
    }

    private function notFound(Request $request): BaseResponse
    {
        $html = $this->view->notFound($request)->render();

        return new Response($html, 404, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
