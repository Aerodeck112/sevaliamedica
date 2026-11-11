<?php

namespace Tests\Feature\Marketing;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Identity\Roles;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Marketing\Services\SeoAuditService;
use App\Modules\Marketing\Services\SeoFixer;
use Database\Seeders\ClinicPublicDataSeeder;
use Tests\TestCase;

/** Corectorul SEO: propuneri sigure, aplicate doar după confirmare, cu scorul recalculat. */
class SeoFixerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicPublicDataSeeder::class);
    }

    private function publish(string $path, string $body, array $seo = [], string $kind = Page::KIND_PAGE): Page
    {
        $page = Page::create(['path' => $path, 'title' => 'Pagină', 'kind' => $kind]);
        $service = app(PageService::class);
        $version = $service->saveVersion($page, [['uid' => 'b1', 'type' => 'html', 'label' => 'Text', 'hidden' => false, 'html' => $body]], $seo, [], null, null);
        $service->publish($page->fresh(), $version, null);

        return $page->fresh();
    }

    private function audit(): SeoAuditRun
    {
        $service = app(SeoAuditService::class);
        $run = $service->start('manual');
        while (! $service->process($run, 30)) {
        }

        return $run->fresh();
    }

    private function body(string $topic, int $repeat = 30): string
    {
        return '<section><div class="container"><h1>'.$topic.'</h1><p>'.str_repeat("Kinetoterapia ajută pacienții să își recapete mobilitatea după o accidentare. Programul este stabilit de terapeut după evaluare. ", $repeat).'</p></div></section>';
    }

    public function test_title_and_description_proposals_stay_within_limits_and_reuse_existing_text(): void
    {
        $page = new Page(['path' => '/x', 'title' => 'X', 'kind' => Page::KIND_ARTICLE]);
        $this->assertSame('Deficitul de vitamina D | Blog Sevalia',
            SeoFixer::fitTitle('Deficitul de vitamina D - prețul pe care îl plătim pentru îndepărtarea de natură | sevalia.ro', $page));
        $page = new Page(['path' => '/termeni', 'title' => 'Termeni', 'kind' => Page::KIND_PAGE]);
        $this->assertSame('Termeni și condiții | SEVALIA Târgu Mureș', SeoFixer::fitTitle('Termeni și condiții | SEVALIA', $page));

        $long = 'Reumatologia este ramura medicinei care se ocupă cu diagnosticarea și tratamentul afecțiunilor musculo-scheletale și autoimune. Medicii noștri reumatologi oferă consultații complete, investigații și planuri de tratament personalizate pentru fiecare pacient.';
        $desc = SeoFixer::fitDescription($long, '', $page);
        $this->assertGreaterThanOrEqual(70, mb_strlen($desc));
        $this->assertLessThanOrEqual(160, mb_strlen($desc));
        $this->assertStringStartsWith('Reumatologia este ramura medicinei', $desc);
        $this->assertStringEndsWith('autoimune.', $desc, 'se păstrează fraze întregi');
    }

    public function test_corrector_applies_selected_fixes_and_score_improves(): void
    {
        $long = $this->publish('/kinetoterapie', $this->body('Kinetoterapie'), ['title' => str_repeat('Kinetoterapie în Târgu Mureș pentru adulți și copii ', 3), 'description' => 'Scurt.']);
        $contact = $this->publish('/contactus', '<section><h1>Contact</h1><p>Scrieți-ne.</p></section>', ['title' => 'Contact și programări – Sevalia Târgu Mureș', 'description' => str_repeat('Contactați clinica Sevalia pentru programări. ', 3)]);
        $thanks = $this->publish('/contactus-thank-you', '<section><p>Mulțumim!</p></section>', ['title' => 'Mulțumim']);
        $run = $this->audit();
        $before = $run->score;

        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->get(route('panou.marketing.seo.fix'))->assertOk()
            ->assertSee('Corecturi automate')->assertSee('Tip pagină: informativă', false)->assertSee('Exclude din Google', false);

        $proposals = app(SeoFixer::class)->proposals($run);
        $fields = collect($proposals['fixes'])->groupBy('path')->map(fn ($g) => $g->pluck('proposed', 'field'));
        $this->assertLessThanOrEqual(65, mb_strlen($fields['/kinetoterapie']['title']));
        $this->assertSame('informativă', $fields['/contactus']['page_role']);
        $this->assertSame('exclusă', $fields['/contactus-thank-you']['noindex']);

        $post = ['use' => [], 'fix' => []];
        foreach ($proposals['fixes'] as $f) {
            $post['use'][$f['page_id']][$f['field']] = '1';
            $post['fix'][$f['page_id']][$f['field']] = $f['field'] === 'description' && $f['path'] === '/kinetoterapie'
                ? 'Kinetoterapie în Târgu Mureș: programe de recuperare stabilite de terapeut după evaluare, pentru adulți și copii.' : $f['proposed'];
        }
        $this->withRecentReauth()->post(route('panou.marketing.seo.fix.apply'), $post)->assertRedirect(route('panou.marketing.seo'));

        $this->assertTrue((bool) $thanks->fresh()->publishedVersion->seo['noindex']);
        $this->assertSame('info', $contact->fresh()->publishedVersion->seo['page_role']);
        $this->assertStringStartsWith('Kinetoterapie în Târgu Mureș: programe', $long->fresh()->publishedVersion->seo['description'], 'textul modificat de om se aplică');
        $this->assertSame(2, $long->fresh()->versions()->count(), 'versiunea veche rămâne în istoric');
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.seo_fix']);

        $service = app(SeoAuditService::class);
        $after = SeoAuditRun::latest('id')->first();
        while (! $service->process($after, 30)) {
        }
        $this->assertGreaterThan($before, $after->fresh()->score);
        $this->assertSame(100, $after->fresh()->score);
    }

    public function test_unchecked_fixes_are_not_applied_and_drafts_are_protected(): void
    {
        $page = $this->publish('/kinetoterapie', $this->body('Kinetoterapie'), ['title' => 'Scurt', 'description' => 'Scurt.']);
        // Ciornă nepublicată peste versiunea publicată.
        app(PageService::class)->saveVersion($page, $page->publishedVersion->blocks, ['title' => 'Ciornă'], [], null, null);
        $run = $this->audit();
        $fix = collect(app(SeoFixer::class)->proposals($run)['fixes'])->firstWhere('path', '/kinetoterapie');
        $this->assertNotNull($fix['blocked']);

        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->withRecentReauth()->post(route('panou.marketing.seo.fix.apply'), ['fix' => [$page->id => ['title' => 'Kinetoterapie în Târgu Mureș | SEVALIA']], 'use' => [$page->id => ['title' => '1']]])
            ->assertRedirect();
        $this->assertSame('Scurt', $page->fresh()->publishedVersion->seo['title']);
        $this->assertSame('Ciornă', $page->fresh()->currentVersion->seo['title'], 'ciorna nu se pierde');
    }

    public function test_receptionist_cannot_use_the_corrector(): void
    {
        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.marketing.seo.fix'))->assertForbidden();
    }
}
