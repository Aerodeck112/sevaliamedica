<?php

namespace Tests\Feature\Cms;

use App\Core\Settings\Settings;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Identity\Roles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MediaRedirectConsentTest extends TestCase
{
    use ImportsSite;

    private string $mediaDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaDir = storage_path('framework/testing/media');
        File::deleteDirectory($this->mediaDir);
        config(['sevalia.media_path' => $this->mediaDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaDir);
        parent::tearDown();
    }

    public function test_invalid_uploads_are_rejected(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        $php = UploadedFile::fake()->createWithContent('poza.jpg', '<?php system($_GET["c"]); ?>');
        $this->post(route('panou.cms.media.store'), ['files' => [$php]])->assertSessionHasErrors('file');

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post(route('panou.cms.media.store'), ['files' => [$svg]])->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
    }

    public function test_valid_image_is_reencoded_with_responsive_variants(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        $image = imagecreatetruecolor(1600, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 196, 196));
        $tmp = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        imagejpeg($image, $tmp, 90);
        // Metadate străine adăugate după imagine (ex. un comentariu sau date ascunse) dispar la reencodare.
        file_put_contents($tmp, file_get_contents($tmp).'SECRET-METADATA');

        $file = new UploadedFile($tmp, 'cabinet.jpg', 'image/jpeg', null, true);
        $this->post(route('panou.cms.media.store'), ['files' => [$file], 'alt' => 'Cabinet (imagine de test)'])->assertSessionHasNoErrors();

        $media = Media::sole();
        $this->assertSame(1600, $media->width);
        $this->assertSame('Cabinet (imagine de test)', $media->alt);
        $this->assertStringNotContainsString('SECRET-METADATA', (string) file_get_contents($media->absolutePath()));
        $this->assertArrayHasKey('1024', $media->variants['webp']);
        $this->assertFileExists($this->mediaDir.'/'.$media->variants['webp']['1024']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.media.uploaded', 'subject_id' => $media->id]);
    }

    public function test_redirects_reject_loops_and_flatten_chains(): void
    {
        $this->importSite();
        $this->actingAsRole(Roles::EDITOR_SITE);

        $this->post(route('panou.cms.redirects.store'), ['source_path' => '/vechi-a', 'target' => '/vechi-b', 'status_code' => 301])->assertSessionHasNoErrors();
        $this->post(route('panou.cms.redirects.store'), ['source_path' => '/vechi-b', 'target' => '/kinetoterapie', 'status_code' => 301])->assertSessionHasNoErrors();
        // A → B a devenit A → țintă finală (fără lanț).
        $this->assertSame('/kinetoterapie', Redirect::where('source_path', '/vechi-a')->value('target'));
        $this->get('/vechi-a')->assertStatus(301)->assertRedirect('/kinetoterapie');

        // C → A se salvează direct spre ținta finală.
        $this->post(route('panou.cms.redirects.store'), ['source_path' => '/vechi-c', 'target' => '/vechi-a', 'status_code' => 301]);
        $this->assertSame('/kinetoterapie', Redirect::where('source_path', '/vechi-c')->value('target'));

        $this->post(route('panou.cms.redirects.store'), ['source_path' => '/bucla', 'target' => '/bucla', 'status_code' => 301])->assertSessionHasErrors('target');
        $this->post(route('panou.cms.redirects.store'), ['source_path' => '/kinetoterapie', 'target' => '/', 'status_code' => 301])->assertSessionHasErrors('source_path');
        $this->post(route('panou.cms.redirects.store'), ['source_path' => '/x', 'target' => 'javascript:alert(1)', 'status_code' => 301])->assertSessionHasErrors('target');
    }

    public function test_tracking_tags_load_only_with_consent_and_respect_exclusions(): void
    {
        $this->importSite();
        $normal = $this->get('/kinetoterapie');
        $this->assertStringNotContainsString('googletagmanager', (string) $normal->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString("gtag('consent'", (string) $normal->getContent());

        $this->actingAsRole(Roles::MANAGER);
        $this->put(route('panou.cms.tracking.update'), ['tags' => [[
            'type' => 'google', 'id' => 'AW-16584116716', 'category' => 'marketing', 'active' => '1', 'excluded' => "/contactus\n/contactus-thank-you",
        ]]])->assertSessionHasNoErrors();
        auth()->logout();

        $page = $this->get('/kinetoterapie')->assertOk();
        $this->assertStringContainsString('https://www.googletagmanager.com', (string) $page->headers->get('Content-Security-Policy'));
        $html = (string) $page->getContent();
        $this->assertStringContainsString("gtag('consent','default',{ad_storage:'denied'", $html);
        // Scriptul Google nu este în pagină: se încarcă din JS doar după consimțământ.
        $this->assertStringNotContainsString('<script async src="https://www.googletagmanager.com', $html);
        $this->assertStringContainsString('"id":"AW-16584116716"', $html);

        $contact = $this->get('/contactus');
        $this->assertStringNotContainsString('googletagmanager', (string) $contact->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('AW-16584116716', (string) $contact->getContent());

        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->put(route('panou.cms.tracking.update'), ['tags' => []])->assertForbidden();
        $this->actingAsRole(Roles::MANAGER);
        $this->put(route('panou.cms.tracking.update'), ['tags' => [['type' => 'google', 'id' => 'x<script>', 'category' => 'marketing']]])->assertSessionHasErrors('tags.0.id');
    }

    public function test_cookie_banner_has_equal_reject_and_editable_texts(): void
    {
        $this->importSite();
        $html = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('data-consent="reject"', $html);
        $this->assertStringContainsString('data-consent="accept"', $html);
        $this->assertStringContainsString('data-cookie-settings', $html); // „Politica de cookie” din subsol

        app(Settings::class)->set('site.cookie', ['title' => 'Titlu cookie de test']);
        \App\Modules\Site\Services\PageCache::flush();
        $this->get('/')->assertSee('Titlu cookie de test');
    }
}
