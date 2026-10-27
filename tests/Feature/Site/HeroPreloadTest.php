<?php

namespace Tests\Feature\Site;

use App\Modules\Cms\Models\Media;
use App\Modules\Site\Services\HtmlCompiler;
use Tests\TestCase;

/** Imaginea principală se preîncarcă pentru ecranele pe care e vizibilă coperta (Odoo: variantă desktop / telefon). */
class HeroPreloadTest extends TestCase
{
    private function media(string $name): Media
    {
        return Media::forceCreate([
            'path' => "import/$name.jpg", 'original_name' => "$name.jpg", 'mime' => 'image/jpeg', 'size' => 1000,
            'width' => 1920, 'height' => 1080, 'sha256' => hash('sha256', $name), 'source' => 'import',
            'variants' => ['webp' => ['768' => "import/$name-768.webp", '1440' => "import/$name-1440.webp", '1920' => "import/$name-1920.webp"]],
        ]);
    }

    public function test_desktop_and_phone_covers_preload_their_own_images(): void
    {
        $desktop = $this->media('desktop-demo');
        $phone = $this->media('telefon-demo');
        $compiler = new HtmlCompiler;
        $compiler->compile('<section class="s_cover d-none d-lg-flex o_snippet_mobile_invisible"><span data-cms-media-bg="'.$desktop->id.'"></span></section>', true);
        $this->assertFalse($compiler->preloadsComplete(), 'telefonul nu are încă imagine preîncărcată');
        $compiler->compile('<section class="s_cover d-lg-none o_snippet_desktop_invisible"><span data-cms-media-bg="'.$phone->id.'"></span></section>', true);
        $this->assertTrue($compiler->preloadsComplete());

        $byMedia = collect($compiler->preloads())->mapWithKeys(fn ($p) => [$p['media'] => basename($p['href'])]);
        $this->assertSame('telefon-demo-768.webp', $byMedia['(max-width: 767.98px)']);
        $this->assertSame('telefon-demo-1440.webp', $byMedia['(min-width: 768px) and (max-width: 991.98px)']);
        $this->assertSame('desktop-demo-1440.webp', $byMedia['(min-width: 992px) and (max-width: 1439.98px)']);
        $this->assertSame('desktop-demo-1920.webp', $byMedia['(min-width: 1440px)']);
        $this->assertCount(4, $compiler->preloads(), 'o singură imagine pe fiecare interval de ecran');
    }

    public function test_cover_visible_everywhere_preloads_once(): void
    {
        $hero = $this->media('acasa-demo');
        $other = $this->media('alta-demo');
        $compiler = new HtmlCompiler;
        $compiler->compile('<section class="s_cover"><span data-cms-media-bg="'.$hero->id.'"></span></section>', true);
        $this->assertTrue($compiler->preloadsComplete());
        $compiler->compile('<section class="s_text_image"><span data-cms-media-bg="'.$other->id.'"></span></section>', true);
        $this->assertCount(4, $compiler->preloads());
        $this->assertStringNotContainsString('alta-demo', json_encode($compiler->preloads()));
    }
}
