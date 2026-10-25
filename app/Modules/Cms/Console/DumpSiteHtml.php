<?php

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\SectionTemplate;
use App\Modules\Site\Services\SiteView;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Scrie HTML-ul randat al tuturor paginilor publicate și al șabloanelor de secțiune în storage/app/site-css/,
 * ca intrare pentru reducerea CSS-ului original (tools/site-css/build.mjs). Se rulează în dezvoltare/CI.
 */
class DumpSiteHtml extends Command
{
    protected $signature = 'sevalia:site-html-dump {--dir=storage/app/site-css}';

    protected $description = 'Exportă HTML-ul paginilor și al șabloanelor pentru construirea CSS-ului siteului';

    public function handle(SiteView $view): int
    {
        $dir = base_path($this->option('dir'));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        array_map('unlink', glob($dir.'/*.html') ?: []);
        app()->instance('csp-nonce', 'x');

        $n = 0;
        foreach (Page::published()->whereIn('kind', [Page::KIND_PAGE, Page::KIND_ARTICLE])->with('publishedVersion')->get() as $page) {
            $request = Request::create($page->path);
            file_put_contents($dir.'/page-'.$page->id.'.html', $view->page($page, $page->publishedVersion, $request)->render());
            $n++;
        }
        file_put_contents($dir.'/not-found.html', $view->notFound(Request::create('/x'))->render());
        $templates = SectionTemplate::all()->map(fn ($t) => $t->block['html'] ?? '')->implode("\n");
        file_put_contents($dir.'/templates.html', $templates);
        $this->info("$n pagini exportate în {$this->option('dir')}");

        return self::SUCCESS;
    }
}
