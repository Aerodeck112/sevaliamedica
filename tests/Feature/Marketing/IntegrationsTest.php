<?php

namespace Tests\Feature\Marketing;

use App\Core\Settings\Settings;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Identity\Roles;
use App\Modules\Marketing\Http\Controllers\IntegrationsController;
use App\Modules\Marketing\Models\IndexSubmission;
use App\Modules\Marketing\Services\IndexNowService;
use Database\Seeders\ClinicPublicDataSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Google Search Console, Google Business, IndexNow, llms.txt și accesul crawlerilor AI. */
class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicPublicDataSeeder::class);
        config(['sevalia.site.canonical_host' => 'https://www.sevalia.ro']);
    }

    private function publish(string $path, string $title, array $seo = []): Page
    {
        $page = Page::create(['path' => $path, 'title' => $title, 'kind' => Page::KIND_PAGE]);
        $service = app(PageService::class);
        $version = $service->saveVersion($page, [['uid' => 'b1', 'type' => 'html', 'label' => 'Text', 'hidden' => false,
            'html' => '<section><h1>'.e($title).'</h1><p>Text demonstrativ suficient de lung pentru a forma o descriere a paginii în motoarele de căutare.</p></section>']], $seo, [], null, null);
        $service->publish($page->fresh(), $version, null);

        return $page->fresh();
    }

    private function save(array $overrides = []): void
    {
        $this->put(route('panou.marketing.integrations.update'), $overrides + [
            'google' => '', 'bing' => '', 'gsc_property' => '', 'gbp_url' => '', 'indexnow' => 0, 'llms' => 1, 'llms_summary' => '', 'llms_notes' => '', 'ai' => 'permite',
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_verification_codes_and_business_profile_reach_the_home_page(): void
    {
        $this->publish('/', 'Acasă');
        $this->publish('/masaj', 'Masaj');
        $this->actingAsRole(Roles::MANAGER);
        $this->get(route('panou.marketing.integrations'))->assertOk()->assertSee('Google Search Console');
        $this->save([
            'google' => '<meta name="google-site-verification" content="AbCdEf123456_-xyz" />',
            'bing' => 'BING1234567890ABCDEF',
            'gsc_property' => 'sc-domain:sevalia.ro',
            'gbp_url' => 'https://maps.app.goo.gl/demo-fictiv',
        ]);
        $this->assertSame('AbCdEf123456_-xyz', app(Settings::class)->get('marketing.verification.google'));

        $home = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('<meta name="google-site-verification" content="AbCdEf123456_-xyz">', $home);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="BING1234567890ABCDEF">', $home);
        $this->assertMatchesRegularExpression('#"sameAs":\[[^\]]*"https://maps.app.goo.gl/demo-fictiv"#', $home, 'profilul Google Business e legat în datele structurate');
        $this->assertStringNotContainsString('google-site-verification', (string) $this->get('/masaj')->getContent());

        $this->get(route('panou.marketing.integrations'))->assertSee('search-console/inspect?resource_id=sc-domain%3Asevalia.ro', false);
        $this->put(route('panou.marketing.integrations.update'), ['google' => '<script>alert(1)</script>', 'ai' => 'permite'])->assertSessionHasErrors('google');
    }

    public function test_robots_ai_modes_and_llms_txt(): void
    {
        config(['sevalia.stage' => 'production']);
        $this->publish('/', 'Acasă', ['description' => 'Clinică de recuperare medicală din Târgu Mureș (descriere demonstrativă).']);
        $this->publish('/kinetoterapie', 'Kinetoterapie', ['description' => 'Kinetoterapie în Târgu Mureș (text demonstrativ).', 'service_name' => 'Kinetoterapie']);

        $robots = (string) $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("Disallow: /panou\n", $robots);
        $this->assertStringContainsString("Disallow: /tableta\n", $robots);
        $this->assertStringNotContainsString('GPTBot', $robots, 'implicit, asistenții AI au acces');

        $this->actingAsRole(Roles::MANAGER);
        $this->save(['ai' => 'doar_cautare']);
        $robots = (string) $this->get('/robots.txt')->getContent();
        $this->assertStringContainsString("User-agent: GPTBot\n", $robots);
        $this->assertStringContainsString("User-agent: Google-Extended\n", $robots);
        $this->assertStringNotContainsString('OAI-SearchBot', $robots, 'căutarea AI rămâne permisă');
        $this->save(['ai' => 'blocheaza']);
        $this->assertStringContainsString('User-agent: PerplexityBot', (string) $this->get('/robots.txt')->getContent());

        $llms = $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $text = (string) $llms->getContent();
        $this->assertStringStartsWith('# Sevalia Medica', $text);
        $this->assertStringContainsString('> Clinică de recuperare medicală din Târgu Mureș', $text);
        $this->assertStringContainsString('## Servicii', $text);
        $this->assertStringContainsString('- [Kinetoterapie](https://www.sevalia.ro/kinetoterapie): Kinetoterapie în Târgu Mureș', $text);
        $this->assertStringContainsString('## Locații și contact', $text);

        $this->save(['llms' => 0]);
        $this->get('/llms.txt')->assertNotFound();
    }

    public function test_indexnow_key_queue_and_submission(): void
    {
        config(['sevalia.stage' => 'production']);
        $this->publish('/', 'Acasă');
        $key = app(IndexNowService::class)->key();
        $this->get('/'.$key.'.txt')->assertNotFound();

        $this->actingAsRole(Roles::MANAGER);
        $this->save(['indexnow' => 1]);
        $this->get('/'.$key.'.txt')->assertOk()->assertSeeText($key);
        $this->get('/'.str_repeat('a', 32).'.txt')->assertNotFound();

        // Publicarea unei pagini o pune în coadă; cronul o trimite.
        $this->publish('/masaj', 'Masaj');
        $this->assertTrue(DB::table('index_queue')->where('path', '/masaj')->exists());

        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        $submission = app(IndexNowService::class)->flush();
        $this->assertSame(202, $submission->status_code);
        Http::assertSent(fn (Request $r) => $r->url() === IndexNowService::ENDPOINT
            && $r['host'] === 'www.sevalia.ro' && $r['key'] === $key && $r['keyLocation'] === "https://www.sevalia.ro/$key.txt"
            && in_array('https://www.sevalia.ro/masaj', $r['urlList'], true));
        $this->assertSame(0, DB::table('index_queue')->count());

        $this->post(route('panou.marketing.integrations.indexnow'))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(2, IndexSubmission::count());
    }

    public function test_indexnow_never_sends_outside_production_and_permissions(): void
    {
        config(['sevalia.stage' => 'staging']);
        app(Settings::class)->set('marketing.indexnow.enabled', true);
        $this->publish('/masaj', 'Masaj');
        Http::fake();
        $this->assertNull(app(IndexNowService::class)->flush());
        Http::assertNothingSent();

        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->get(route('panou.marketing.integrations'))->assertForbidden();
        $this->actingAsRole(Roles::MEDIC);
        $this->get(route('panou.marketing.seo'))->assertForbidden();
        $this->assertSame('abcdefghijKLMNOP_123', IntegrationsController::verificationToken('abcdefghijKLMNOP_123'));
        $this->assertNull(IntegrationsController::verificationToken('scurt'));
    }
}
