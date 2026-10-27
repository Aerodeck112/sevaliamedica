<?php

namespace Tests\Feature\Marketing;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Identity\Roles;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Marketing\Models\SeoKeyword;
use App\Modules\Marketing\Services\KeywordMatcher;
use App\Modules\Marketing\Services\PageAnalyzer;
use App\Modules\Marketing\Services\SeoAuditService;
use Database\Seeders\ClinicPublicDataSeeder;
use Tests\TestCase;

/** Pilotul automat de audit SEO: analiza paginii, cuvintele cheie, rulările pe loturi și accesul din panou. */
class SeoAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicPublicDataSeeder::class);
    }

    private function publish(string $path, string $title, string $body, array $seo = []): Page
    {
        $page = Page::create(['path' => $path, 'title' => $title, 'kind' => Page::KIND_PAGE]);
        $service = app(PageService::class);
        $version = $service->saveVersion($page, [['uid' => 'b1', 'type' => 'html', 'label' => 'Text', 'hidden' => false, 'html' => $body]], $seo, [], null, null);
        $service->publish($page->fresh(), $version, null);

        return $page->fresh();
    }

    private function longText(string $topic): string
    {
        return '<p>'.str_repeat("Text demonstrativ despre $topic, cu explicații pentru pacienți și pași de recuperare. ", 30).'</p>';
    }

    public function test_keyword_matching_ignores_diacritics_and_tg_abbreviation(): void
    {
        $this->assertSame(KeywordMatcher::normalize('Kinetoterapie Târgu Mureș'), KeywordMatcher::normalize('kinetoterapie tg. mures'));
        $m = KeywordMatcher::match('masaj terapeutic Tg. Mureș', [
            'title' => 'Masaj terapeutic Târgu Mureș | SEVALIA', 'h1' => 'MASAJ', 'description' => 'Masaj în Târgu Mureș, terapeutic și medical.', 'text' => '',
        ]);
        $this->assertSame(['title' => 'exact', 'h1' => 'lipsa', 'description' => 'partial', 'text' => 'lipsa'], $m['fields']);
        $this->assertSame(50, $m['score']);
    }

    public function test_page_analyzer_reports_real_problems(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Scurt</title><script type="application/ld+json">{nu e json</script></head><body><main>'
            .'<h1>Unu</h1><h1>Doi</h1><img src="/a.jpg"><img src="/b.jpg" alt=""><a href="/pagina-inexistenta">x</a>'
            .'<img src="https://www.sevalia.ro/web/image/123">'
            .'</main></body></html>';
        $result = app(PageAnalyzer::class)->analyze($html, '/test', 'https://www.sevalia.ro');
        $codes = array_column($result['issues'], 'code');
        foreach (['title_length', 'desc_missing', 'h1_multiple', 'canonical_missing', 'lang_missing', 'img_alt_missing', 'link_broken', 'legacy_reference', 'jsonld_invalid', 'thin_content'] as $code) {
            $this->assertContains($code, $codes, $code);
        }
        $this->assertSame(2, $result['metrics']['images_without_alt']);
        $this->assertLessThan(50, PageAnalyzer::score($result['issues']));
    }

    public function test_full_run_in_batches_with_duplicates_and_keywords(): void
    {
        $this->publish('/kinetoterapie', 'Kinetoterapie', '<section><h1>KINETOTERAPIE</h1>'.$this->longText('kinetoterapie Târgu Mureș').'</section>',
            ['title' => 'Kinetoterapie Târgu Mureș pentru adulți și copii | SEVALIA', 'description' => 'Kinetoterapie în Târgu Mureș pentru adulți și copii: programe personalizate de recuperare (text demonstrativ).']);
        $this->publish('/masaj', 'Masaj', '<section><h1>MASAJ</h1>'.$this->longText('masaj').'</section>', ['title' => 'Același titlu pentru două pagini']);
        $this->publish('/drenaj-limfatic', 'Drenaj', '<section><h1>DRENAJ</h1>'.$this->longText('drenaj').'</section>', ['title' => 'Același titlu pentru două pagini']);
        SeoKeyword::query()->delete();
        SeoKeyword::create(['phrase' => 'kinetoterapie Tg. Mureș', 'target_path' => '/kinetoterapie']);
        SeoKeyword::create(['phrase' => 'neurologie Mureș', 'target_path' => '/neurologie']);

        $audit = app(SeoAuditService::class);
        $run = $audit->start('cron');
        $this->assertSame(3, $run->pages_total);
        $this->assertSame($run->id, $audit->start('cron')->id, 'o singură rulare odată');

        $this->assertFalse($audit->process($run, 20, 1), 'primul lot verifică o singură pagină');
        $this->assertTrue($audit->process($run->fresh(), 20, 10));
        $run->refresh();
        $this->assertSame('finalizat', $run->status);
        $this->assertSame(3, $run->pages_done);
        $this->assertNotNull($run->score);

        $masaj = $run->pages()->where('path', '/masaj')->first();
        $this->assertContains('title_duplicate', array_column($masaj->issues, 'code'));
        $this->assertContains('desc_auto', array_column($masaj->issues, 'code'), 'descrierea lipsă se completează din text');
        $this->assertArrayNotHasKey('text_sample', $masaj->metrics, 'textul paginilor nu se păstrează');

        $keywords = collect($run->site_checks['keywords'])->keyBy('phrase');
        $this->assertSame('exact', $keywords['kinetoterapie Tg. Mureș']['fields']['title']);
        $this->assertSame('exact', $keywords['kinetoterapie Tg. Mureș']['fields']['text']);
        $this->assertSame('lipsa', $keywords['kinetoterapie Tg. Mureș']['fields']['h1'], 'H1-ul original „KINETOTERAPIE” nu conține orașul');
        $this->assertNotNull($keywords['neurologie Mureș']['problem'], 'pagina țintă lipsă e semnalată');
        $this->assertContains('gsc_missing', array_column($run->site_checks['site'], 'code'));
    }

    public function test_cron_command_starts_and_continues(): void
    {
        $this->publish('/kinetoterapie', 'Kinetoterapie', '<section><h1>KINETOTERAPIE</h1>'.$this->longText('kinetoterapie').'</section>');
        $this->artisan('sevalia:seo-audit')->assertSuccessful();
        $this->assertSame(0, SeoAuditRun::count(), 'fără --start nu pornește nimic');
        $this->artisan('sevalia:seo-audit --start')->expectsOutputToContain('finalizat')->assertSuccessful();
        $this->assertSame('finalizat', SeoAuditRun::sole()->status);
    }

    public function test_panel_access_and_manual_run(): void
    {
        $this->publish('/kinetoterapie', 'Kinetoterapie', '<section><h1>KINETOTERAPIE</h1>'.$this->longText('kinetoterapie').'</section>');

        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.marketing.seo'))->assertForbidden();

        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->get(route('panou.marketing.seo'))->assertOk()->assertSee('Încă nu există un audit');
        $this->post(route('panou.marketing.seo.start'))->assertRedirect(route('panou.marketing.seo'));
        $this->get(route('panou.marketing.seo'))->assertOk()->assertSee('Scor SEO al siteului')->assertSee('/kinetoterapie')->assertSee('kinetoterapie Târgu Mureș');
        $this->get(route('panou.marketing.integrations'))->assertForbidden();

        $this->get(route('panou.marketing.keywords'))->assertOk();
        $this->post(route('panou.marketing.keywords.store'), ['phrase' => 'fizioterapie Târgu Mureș', 'target_path' => '/kinetoterapie'])->assertRedirect();
        $this->post(route('panou.marketing.keywords.store'), ['phrase' => 'fizioterapie Târgu Mureș', 'target_path' => '/kinetoterapie'])->assertSessionHasErrors('phrase');
        $kw = SeoKeyword::where('phrase', 'fizioterapie Târgu Mureș')->sole();
        $this->put(route('panou.marketing.keywords.update', $kw), ['phrase' => 'fizioterapie Mureș', 'target_path' => '/kinetoterapie'])->assertRedirect();
        $this->assertFalse($kw->fresh()->is_active);
        $this->delete(route('panou.marketing.keywords.destroy', $kw))->assertRedirect();
        $this->assertNull($kw->fresh());
    }
}
