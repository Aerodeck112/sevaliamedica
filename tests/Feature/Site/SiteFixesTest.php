<?php

namespace Tests\Feature\Site;

use App\Modules\Clinic\Models\Location;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Cms\Services\SiteFixes;
use App\Modules\Site\Services\A11y;
use App\Modules\Site\Services\SiteIcons;
use Database\Seeders\ClinicPublicDataSeeder;
use Tests\TestCase;

class SiteFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicPublicDataSeeder::class);
    }

    private function publish(string $path, string $html, array $seo = []): Page
    {
        $page = Page::create(['path' => $path, 'title' => 'Pagină', 'kind' => Page::KIND_PAGE]);
        $service = app(PageService::class);
        $version = $service->saveVersion($page, [['uid' => 'b1', 'type' => 'html', 'label' => 'Text', 'hidden' => false, 'html' => $html]], $seo, [], null, null);
        $service->publish($page->fresh(), $version, null);

        return $page;
    }

    public function test_h1_texts_and_seo_titles_are_fixed_once(): void
    {
        $this->publish('/', '<section><div class="container"><h1>Acasă</h1></div></section>');
        $this->publish('/ultrasonoterapie', '<section class="s_cover"><h1 class="display-4" style="text-align: left;">ULTRASONONTERAPIE</h1></section><p>Bai galvanice și Dus subacval</p>', ['title' => 'Ultrasonoterapie | Terapie cu ultrasunete']);
        $this->publish('/servicii', '<section><h1><br></h1></section><section><h1 style="text-align: left;"><strong>SERVICIILE NOASTRE</strong><br></h1></section>');
        $this->publish('/termeni-si-conditii', '<section><h1 style="text-align: left;"><br></h1><h3>Termeni și condiții</h3><p>Text [u1] legal.</p></section>');

        $first = SiteFixes::run(app(PageService::class));
        $this->assertContains('/ultrasonoterapie', $first['pages']);
        $this->assertSame([], SiteFixes::run(app(PageService::class))['pages'], 'a doua aplicare nu mai modifică nimic');

        $html = $this->get('/ultrasonoterapie')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('~<h1\b~', $html));
        $this->assertStringContainsString('style="text-transform: uppercase; text-align: left;">Ultrasonoterapie în Târgu Mureș</h1>', $html);
        $this->assertStringContainsString('Băi galvanice și Duș subacval', $html);
        $this->assertStringContainsString('<title>Ultrasonoterapie Târgu Mureș – terapie cu ultrasunete | SEVALIA</title>', $html);

        $html = $this->get('/servicii')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('~<h1\b~', $html));
        $this->assertStringContainsString('>Servicii de recuperare medicală în Târgu Mureș</h1>', $html);

        $html = $this->get('/termeni-si-conditii')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<h1[^>]*>Termeni și condiții</h1>~', $html);
        $this->assertStringNotContainsString('[u1]', $html);
    }

    public function test_missing_pages_location_and_menu_are_created(): void
    {
        $this->publish('/', '<section><div class="container"><h1>Acasă</h1></div></section>');
        $result = SiteFixes::run(app(PageService::class));

        $this->assertEqualsCanonicalizing(['/neurologie', '/dry-needling', '/locatii'], $result['created']);
        $this->get('/neurologie')->assertOk()->assertSee('dr. Orban Emese')->assertSee('Neurologie în Târgu Mureș');
        $this->get('/dry-needling')->assertOk();
        $this->get('/locatii')->assertOk()->assertSee('Strada Păcii 19, 547065 Band')->assertSee('+40 742 281 438');
        $this->assertDatabaseHas('locations', ['slug' => 'band', 'address' => 'Strada Păcii 19', 'city' => 'Band']);
        $menu = MenuItem::whereNull('parent_id')->where('label', 'Locații')->first();
        $this->assertNotNull($menu);
        $this->get('/')->assertSee('Locații')->assertSee('/medicina-de-familie-band-mures', false);

        // A doua aplicare nu dublează nimic și nu suprascrie paginile create (pot fi editate din CMS).
        $again = SiteFixes::run(app(PageService::class));
        $this->assertSame([], $again['created']);
        $this->assertFalse($again['menu']);
        $this->assertSame(1, Location::where('slug', 'band')->count());
    }

    public function test_feature_circles_get_matching_icons(): void
    {
        $html = '<i class="fa fa-chevron-right bg-o-color-1 s_features_grid_icon rounded-circle fa-2x"></i><div class="c"><h4><a href="/magnetoterapie">Magnetoterapie</a></h4></div>'
            .'<i class="fa fa-chevron-right rounded-circle fa-2x"></i><div><h4>Ceva necunoscut</h4></div>'
            .'<i class="fa fa-chevron-right s_mega_menu_little_icons_icon fa-fw"></i> <b>Reumatologie</b>';
        $out = SiteIcons::fix($html);

        $this->assertStringContainsString('fa fa-magnet bg-o-color-1', $out);
        $this->assertSame(2, substr_count($out, 'fa-chevron-right'), 'titlu necunoscut și săgețile din meniu rămân');
    }

    public function test_internal_links_do_not_open_new_tabs(): void
    {
        $out = A11y::fix('<a href="/contactus" target="_blank" rel="noopener">Programează</a><a href="https://maps.google.com/x" target="_blank" rel="noopener">Hartă</a>');

        $this->assertStringContainsString('<a href="/contactus">Programează</a>', $out);
        $this->assertStringContainsString('href="https://maps.google.com/x" target="_blank"', $out);
    }
}
