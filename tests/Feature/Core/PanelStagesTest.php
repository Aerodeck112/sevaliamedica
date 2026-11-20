<?php

namespace Tests\Feature\Core;

use App\Core\Modules\PanelStages;
use App\Modules\Identity\Roles;
use Tests\TestCase;

/** Panoul pe etape: implicit doar esențialul (echipă, servicii, programări, solicitări). */
class PanelStagesTest extends TestCase
{
    public function test_by_default_only_the_essential_menu_is_shown(): void
    {
        $this->actingAsRole(Roles::MANAGER);
        $html = $this->get(route('panou.dashboard'))->assertOk()->getContent();

        $nav = $this->navLabels($html);
        $this->assertContains('Calendar', $nav);
        $this->assertContains('Solicitări', $nav);
        $this->assertContains('Personal', $nav);
        $this->assertContains('Catalog servicii', $nav);
        $this->assertNotContains('Audit SEO', $nav);
        $this->assertNotContains('Planuri recuperare', $nav);
        $this->assertNotContains('Pacienți', $nav);

        // Paginile ascunse rămân accesibile (ex. din linkurile altor pagini); drepturile nu se schimbă.
        $this->get(route('panou.patients.index'))->assertOk();
    }

    public function test_admin_enables_stages_and_the_change_is_audited(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->get(route('panou.features.edit'))->assertOk()->assertSee('Etapa 3. Site și marketing')->assertSee('Audit SEO');
        $this->put(route('panou.features.update'), ['stages' => [3]])->assertRedirect();

        $this->assertSame([1, 3], app(PanelStages::class)->enabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'panel.stages_updated']);

        $this->actingAsRole(Roles::MANAGER);
        $nav = $this->navLabels($this->get(route('panou.dashboard'))->getContent());
        $this->assertContains('Audit SEO', $nav);
        $this->assertNotContains('Pacienți', $nav);
    }

    public function test_stage_one_cannot_be_turned_off_and_others_cannot_change_stages(): void
    {
        app(PanelStages::class)->set([]);
        $this->assertSame([1], app(PanelStages::class)->enabled());

        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.features.edit'))->assertForbidden();
    }

    /** @return list<string> */
    private function navLabels(string $html): array
    {
        preg_match('~<aside class="sidebar".*?</aside>~s', $html, $m);
        preg_match_all('~</svg>\s*([^<]+?)\s*</a>~u', $m[0] ?? '', $labels);

        return array_map('trim', $labels[1]);
    }
}
