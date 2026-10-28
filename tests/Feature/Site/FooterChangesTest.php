<?php

namespace Tests\Feature\Site;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\FooterChanges;
use App\Modules\Cms\Services\PageService;
use Database\Seeders\ClinicPublicDataSeeder;
use Tests\TestCase;

class FooterChangesTest extends TestCase
{
    /** Structura subsolului preluat de pe siteul vechi (prescurtată). */
    private const FOOTER = '<section class="s_text_block"><div class="container"><div class="row o_grid_mode">'
        .'<div class="o_grid_item g-col-lg-4 col-lg-4 g-height-10" style="grid-area: 1 / 1 / 11 / 5;"><p>© Copyright Sevalia Medica 2023. Toate drepturile rezervate!</p></div>'
        .'<div class="o_colored_level o_grid_item g-col-lg-5 col-lg-5 g-height-12" style="z-index: 3; grid-area: 1 / 8 / 13 / 13;">'
        .'<p>REUMATOLOGIE</p><section class="s_map pt136 pb48" data-map-address="Str. Constandin Hagi Stoian, nr. 3"><div class="map_container"></div></section><p><br></p>'
        .'<p>PEDIATRIE</p><section class="s_map pt56 pb128" data-map-address="str. Ion Heliade Radulescu, nr. 86"><div class="map_container"></div></section><p><br></p>'
        .'</div></div></div></section>';

    public function test_copyright_year_and_band_map_are_added_once(): void
    {
        $html = FooterChanges::apply(self::FOOTER);

        $this->assertStringContainsString('Copyright Sevalia Medica 2026', $html);
        $this->assertStringNotContainsString('2023', $html);
        $this->assertSame(3, substr_count($html, 'class="s_map '));
        $this->assertStringContainsString('data-map-address="Band, județul Mureș"', $html);
        $this->assertStringContainsString('MEDICINĂ DE FAMILIE | BAND, JUD. MUREȘ', $html);
        // Harta nouă vine după a doua hartă, în aceeași coloană, care crește ca să o cuprindă.
        $this->assertGreaterThan(strpos($html, 'Heliade'), strpos($html, 'Band, județul'));
        $this->assertStringContainsString('g-height-18" style="z-index: 3; grid-area: 1 / 8 / 19 / 13;"', $html);
        $this->assertStringEndsWith('</div></div></div></section>', $html);
        $this->assertSame($html, FooterChanges::apply($html), 'a doua aplicare nu mai schimbă nimic');
    }

    public function test_published_footer_gets_a_new_version_and_shows_on_site(): void
    {
        $this->seed(ClinicPublicDataSeeder::class);
        $service = app(PageService::class);
        $footer = Page::create(['path' => Page::FOOTER_PATH, 'title' => 'Subsol', 'kind' => Page::KIND_GLOBAL]);
        $v1 = $service->saveVersion($footer, [['uid' => 'f1', 'type' => 'html', 'label' => 'Subsol', 'hidden' => false, 'html' => self::FOOTER]], [], [], null, null);
        $service->publish($footer->fresh(), $v1, null);

        $this->assertTrue(FooterChanges::publish($service));
        $this->assertFalse(FooterChanges::publish($service));

        $footer->refresh();
        $this->assertNotSame($v1->id, $footer->published_version_id);
        $this->assertSame(2, $footer->versions()->count(), 'versiunea veche rămâne în istoric');
        $home = Page::create(['path' => '/', 'title' => 'Acasă', 'kind' => Page::KIND_PAGE]);
        $hv = $service->saveVersion($home, [['uid' => 'h1', 'type' => 'html', 'label' => 'Text', 'hidden' => false, 'html' => '<section class="s_text_block"><div class="container"><p>Acasă</p></div></section>']], [], [], null, null);
        $service->publish($home->fresh(), $hv, null);

        $this->get('/')->assertOk()->assertSee('Copyright Sevalia Medica 2026', false)->assertSee('Band', false)
            ->assertDontSee('js_language_selector', false)->assertDontSee('English (US)', false);
    }

    public function test_without_footer_nothing_happens(): void
    {
        $this->assertFalse(FooterChanges::publish(app(PageService::class)));
    }
}
