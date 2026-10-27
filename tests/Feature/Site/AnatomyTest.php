<?php

namespace Tests\Feature\Site;

use App\Core\Settings\Settings;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Site\Services\PageCache;
use Database\Seeders\ClinicPublicDataSeeder;
use Tests\TestCase;

/** Schițele anatomice și harta corpului se adaugă peste conținut, fără să-l modifice, și se pot opri din panou. */
class AnatomyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicPublicDataSeeder::class);
    }

    private function publish(string $path, string $html): void
    {
        $page = Page::create(['path' => $path, 'title' => 'Pagină demonstrativă', 'kind' => Page::KIND_PAGE]);
        $service = app(PageService::class);
        $version = $service->saveVersion($page, [['uid' => 'b1', 'type' => 'html', 'label' => 'Text', 'hidden' => false, 'html' => $html]], [], [], null, null);
        $service->publish($page->fresh(), $version, null);
    }

    private function cover(string $title): string
    {
        return '<section class="s_cover o_colored_level"><div class="container"><h1>'.$title.'</h1></div></section>'
            .'<section class="s_text_block"><div class="container"><p>Text demonstrativ al paginii, păstrat exact cum a fost importat din siteul original.</p></div></section>';
    }

    public function test_service_cover_gets_a_decorative_sketch_with_the_treated_zone(): void
    {
        $this->publish('/kinetoterapie-atm-articulatia-temporo-mandibulara', $this->cover('KINETOTERAPIE ATM'));
        $this->publish('/politica-de-confidentialitate', $this->cover('Politica de confidențialitate'));

        $html = (string) $this->get('/kinetoterapie-atm-articulatia-temporo-mandibulara')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<section class="s_cover[^"]*">.*<div class="sv-anatomy sv-anatomy--hero" aria-hidden="true">.*</section>#s', $html);
        $this->assertStringContainsString('data-zone="atm"', $html);
        $this->assertStringNotContainsString('data-zone="pelvin"', $html);
        $this->assertStringContainsString('KINETOTERAPIE ATM', $html, 'conținutul original rămâne');
        // Descrierea implicită vine din textul paginii, nu din schiță.
        $this->assertStringContainsString('<meta name="description" content="Text demonstrativ al paginii', $html);

        $this->assertStringNotContainsString('sv-anatomy', (string) $this->get('/politica-de-confidentialitate')->getContent());
    }

    public function test_home_body_map_links_only_to_published_pages_and_can_be_disabled(): void
    {
        $this->publish('/', $this->cover('Acasă').'<section class="s_three"><p>A treia secțiune</p></section><section class="s_four"><p>A patra</p></section>');
        $this->publish('/masaj', $this->cover('MASAJ'));
        $this->publish('/emsella-tratament-pentru-incontinenta-urinara', $this->cover('EMSELLA'));

        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Unde simțiți durere sau disconfort?', $html);
        $this->assertStringContainsString('href="/masaj"', $html);
        $this->assertStringContainsString('href="/emsella-tratament-pentru-incontinenta-urinara"', $html);
        $this->assertStringNotContainsString('href="/decompresie-vertebrala-robotizata"', $html, 'pagina nepublicată nu apare');
        $this->assertStringNotContainsString('Maxilar (ATM)', $html, 'zona fără pagini publicate lipsește');
        $this->assertStringContainsString('Tratamentul potrivit îl stabilește medicul', $html);
        // După a treia secțiune, înaintea celei de-a patra.
        $this->assertLessThan(strpos($html, 'A patra'), strpos($html, 'data-bodymap'));
        $this->assertGreaterThan(strpos($html, 'A treia secțiune'), strpos($html, 'data-bodymap'));

        app(Settings::class)->set('site.body_map', false);
        PageCache::flush();
        $this->assertStringNotContainsString('data-bodymap', (string) $this->get('/')->getContent());

        app(Settings::class)->set('site.anatomy', false);
        PageCache::flush();
        $this->assertStringNotContainsString('sv-anatomy', (string) $this->get('/masaj')->getContent());
    }
}
