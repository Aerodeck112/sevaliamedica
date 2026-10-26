<?php

namespace Tests\Feature\Cms;

use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\Import\RenderedCleaner;
use App\Modules\Cms\Services\Import\SourceCatalog;
use Tests\TestCase;

/**
 * Siteul vechi va fi șters: conținutul importat nu are voie să mai indice spre www.sevalia.ro sau spre resurse Odoo.
 */
class NoLegacyUrlsTest extends TestCase
{
    use ImportsSite;

    private const LEGACY = '~(?:src|href|srcset|data-src|content)="(?!mailto:)[^"]*(?:sevalia\.ro|/web/image|/web/content|/web/static|/unsplash/|/html_editor/|/website/social/)[^"]*"|url\(\s*[\'"]?[^)]*(?:sevalia\.ro|/web/image|/web/content|/unsplash/|/html_editor/)~i';

    public function test_imported_content_has_no_links_to_the_old_site(): void
    {
        $report = $this->importSite();

        $offending = [];
        foreach (Page::with('currentVersion')->get() as $page) {
            foreach ($page->currentVersion->blocks as $block) {
                if (preg_match_all(self::LEGACY, (string) ($block['html'] ?? ''), $m)) {
                    $offending[$page->path] = array_slice($m[0], 0, 3);
                }
            }
        }
        foreach (MenuItem::all() as $item) {
            if (($item->url && preg_match('~sevalia\.ro|/web/~', $item->url)) || preg_match_all(self::LEGACY, (string) ($item->mega_block['html'] ?? ''), $m)) {
                $offending['meniu:'.$item->label] = $m[0] ?? [$item->url];
            }
        }
        $this->assertSame([], $offending, 'Conținut care încă indică siteul vechi: '.json_encode($offending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Linkurile sociale sunt rescrise direct la adresele finale.
        $footer = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('href="https://www.facebook.com/SevaliaMedica/"', $footer);
        $this->assertStringContainsString('href="https://www.youtube.com/@sevaliamedica"', $footer);
        $this->assertStringContainsString('href="https://www.tiktok.com/@sevaliamedica"', $footer);

        // Resursele lipsă sunt raportate explicit, nu ignorate.
        $this->assertIsArray($report['imagini_lipsa']);
    }

    public function test_public_pages_reference_only_local_resources(): void
    {
        $this->importSite();
        foreach (['/', '/kinetoterapie', '/echipa-noastra', '/blog/blog-sevalia-1', '/blog/blog-sevalia-1/incontinenta-urinara-7', '/ghid-intrebari-medicina-interna'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent();
            $body = substr($html, (int) strpos($html, '<body'));
            $this->assertDoesNotMatchRegularExpression(self::LEGACY, $body, "Pagina $path referă resurse ale siteului vechi");
        }
    }

    public function test_catalog_reads_archive_with_priority_and_cleans_rendered_html(): void
    {
        $dir = storage_path('framework/testing/arhiva');
        @mkdir($dir.'/files', 0775, true);
        @mkdir($dir.'/rendered', 0775, true);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        file_put_contents($dir.'/files/a.png', $png);
        file_put_contents($dir.'/index.json', json_encode([
            'https://www.sevalia.ro/web/image/1-x/a.png' => ['file' => 'files/a.png', 'sha256' => hash('sha256', $png), 'pages' => ['/pagina-noua']],
        ]));
        file_put_contents($dir.'/rendered/pagina-noua.html', '<html><body><div id="wrapwrap"><main><div id="wrap"><section class="s_text_block o_animate o_animated" style="visibility: visible; animation-play-state: running;"><h2><span class="o_text_highlight"><svg class="o_text_highlight_svg"></svg>Titlu</span></h2><img src="https://www.sevalia.ro/web/image/1-x/a.png?unique=9"></section></div></main></div><script>alert(1)</script></body></html>');

        $catalog = new SourceCatalog(database_path('content/odoo-snapshot'), $dir);
        $this->assertTrue($catalog->hasArchive());
        $this->assertSame('arhiva', $catalog->asset('/web/image/1-x/a.png?unique=9')['source']);
        $this->assertArrayHasKey('/pagina-noua', $catalog->pages());
        $this->assertTrue($catalog->pages()['/pagina-noua']['rendered']);
        // Pentru paginile existente în ambele surse, structura vine din instantaneu (HTML de server).
        $this->assertFalse($catalog->pages()['/kinetoterapie']['rendered']);

        $clean = RenderedCleaner::clean((string) file_get_contents($dir.'/rendered/pagina-noua.html'));
        $this->assertStringNotContainsString('o_text_highlight_svg', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('o_animated', $clean);
        $this->assertStringNotContainsString('visibility: visible', $clean);
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
}
