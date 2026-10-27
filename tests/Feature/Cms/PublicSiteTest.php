<?php

namespace Tests\Feature\Cms;

use App\Core\Settings\Settings;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Services\Import\SiteImporter;
use App\Modules\Cms\Services\PageService;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use ImportsSite;

    public function test_all_original_urls_respond_as_planned(): void
    {
        $this->importSite();
        $expectedRedirects = ['/blog' => Page::BLOG_PATH, '/website/info' => '/'];

        foreach ($this->snapshotPaths() as $path) {
            $response = $this->get($path);
            if (isset($expectedRedirects[$path])) {
                $response->assertStatus(301)->assertRedirect($expectedRedirects[$path]);

                continue;
            }
            $this->assertSame(200, $response->status(), "Calea $path trebuie să răspundă 200");
            $this->assertNull($response->headers->get('Set-Cookie'), "Pagina publică $path nu trebuie să seteze cookie-uri");
            // Un singur <h1> în conținutul paginii.
            $main = substr((string) $response->getContent(), (int) strpos((string) $response->getContent(), '<main>'));
            $this->assertLessThanOrEqual(1, substr_count($main, '<h1'), "Pagina $path are mai multe <h1>");
        }
    }

    public function test_redirects_for_english_versions_and_blog_pagination(): void
    {
        $this->importSite();
        $this->get('/en/kinetoterapie')->assertStatus(301)->assertRedirect('/kinetoterapie');
        $this->get('/en')->assertStatus(301)->assertRedirect('/');
        $this->get('/en/blog/our-blog-1/what-is-enthesitis-2')->assertStatus(301)->assertRedirect('/blog/blog-sevalia-1/ce-este-enteza-2');
        $this->get('/blog/blog-sevalia-1/page/2')->assertOk()->assertSee('o_wblog_post', false);

        app(Settings::class)->set('site.en_redirect', false);
        $this->get('/en/kinetoterapie')->assertNotFound();
    }

    public function test_missing_page_shows_site_404(): void
    {
        $this->importSite();
        $this->get('/neurologie')->assertNotFound()->assertSee('Nu am putut găsi pagina pe care o căutați!');
    }

    public function test_page_has_seo_basics_and_conditional_requests(): void
    {
        $this->importSite();
        $response = $this->get('/kinetoterapie')->assertOk()
            ->assertSee('<link rel="canonical" href="https://www.sevalia.ro/kinetoterapie">', false)
            ->assertSee('<title>Kinetoterapie Târgu Mureș pentru adulți și copii | SEVALIA</title>', false);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->get('/kinetoterapie', ['If-None-Match' => $etag])->assertStatus(304);

        $home = $this->get('/')->assertOk();
        $this->assertStringContainsString('"@type":"MedicalClinic"', (string) $home->getContent());
        $this->assertStringContainsString('str. Constandin Hagi Stoian, nr. 3C', (string) $home->getContent());

        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>https://www.sevalia.ro/kinetoterapie</loc>', false)
            ->assertDontSee('contactus-thank-you');
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: https://www.sevalia.ro/sitemap.xml');
    }

    public function test_import_is_idempotent_and_keeps_cms_edits(): void
    {
        $this->importSite();
        $second = app(SiteImporter::class)->run(false);
        $this->assertSame([], $second['pagini_create']);
        $this->assertSame([], $second['pagini_actualizate']);

        $page = Page::where('path', '/kinetoterapie')->firstOrFail();
        $blocks = $page->currentVersion->blocks;
        $blocks[0]['hidden'] = true;
        app(PageService::class)->saveVersion($page, $blocks, $page->currentVersion->seo, $page->currentVersion->settings, null, $page->lock_version, 'test');

        $third = app(SiteImporter::class)->run(false);
        $this->assertContains('/kinetoterapie', $third['pagini_editate_sarite']);
        $this->assertTrue($page->fresh()->currentVersion->blocks[0]['hidden']);
    }

    public function test_team_block_follows_staff_records(): void
    {
        $this->importSite();
        $staff = \App\Modules\Clinic\Models\StaffMember::where('display_name', 'dr. Cristina Pop')->firstOrFail();
        $this->get('/echipa-noastra')->assertSee('dr. Cristina Pop');

        $staff->update(['show_on_site' => false]);
        $this->get('/echipa-noastra')->assertDontSee('>dr. Cristina Pop<', false);

        \App\Modules\Clinic\Models\StaffMember::create([
            'kind' => 'medic', 'first_name' => 'Test', 'last_name' => 'Fictiv', 'display_name' => 'dr. Persoană Fictivă',
            'professional_title' => 'medic fictiv pentru test', 'show_on_site' => true, 'site_sort' => 50,
        ]);
        $this->get('/echipa-noastra')->assertSee('dr. Persoană Fictivă')->assertSee('medic fictiv pentru test');
        // Lista medicilor din formular vine tot din fișe.
        $this->get('/contactus')->assertSee('dr. Persoană Fictivă - medic fictiv pentru test')->assertDontSee('dr. Cristina Pop - medic');
    }

    public function test_seeded_redirects_have_no_chains(): void
    {
        $this->importSite();
        foreach (Redirect::all() as $r) {
            $this->assertFalse(Redirect::where('source_path', $r->target)->exists(), "Lanț de redirect: {$r->source_path} → {$r->target}");
        }
    }
}
