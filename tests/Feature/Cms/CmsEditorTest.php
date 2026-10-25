<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Cms\Models\SectionTemplate;
use App\Modules\Cms\Services\Blocks\Slots;
use App\Modules\Cms\Services\Html\StructureSanitizer;
use App\Modules\Cms\Services\Import\SiteImporter;
use App\Modules\Cms\Services\PageService;
use App\Modules\Identity\Roles;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CmsEditorTest extends TestCase
{
    use ImportsSite;

    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ClinicPublicDataSeeder::class);
        $importer = app(SiteImporter::class);
        $importer->run(false);
        $importer->saveTemplates();
        $this->page = Page::where('path', '/kinetoterapie')->firstOrFail();
    }

    /** Primul slot de text din primul bloc cu text. @return array{0:string,1:string,2:string} uid, slot, text */
    private function firstTextSlot(): array
    {
        foreach ($this->page->fresh()->currentVersion->blocks as $block) {
            foreach (Slots::extract($block['html'] ?? '') as $slot) {
                if ($slot['kind'] === 'text') {
                    return [$block['uid'], $slot['id'], (string) $slot['value']];
                }
            }
        }
        $this->fail('Nu există sloturi de text.');
    }

    public function test_permissions_per_role(): void
    {
        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.cms.pages.index'))->assertForbidden();
        $this->get(route('panou.cms.pages.edit', $this->page))->assertForbidden();

        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->get(route('panou.cms.pages.index'))->assertOk()->assertSee('/kinetoterapie');
        $this->get(route('panou.cms.pages.edit', $this->page))->assertOk()->assertSee('Salvează ciorna');
        $this->get(route('panou.cms.tracking.edit'))->assertForbidden();
        $this->get(route('panou.cms.media.index'))->assertOk();

        $this->actingAsRole(Roles::MANAGER);
        $this->get(route('panou.cms.tracking.edit'))->assertOk()->assertSee('Paginile cu formulare pot conține date de sănătate');
    }

    public function test_draft_is_not_public_until_published(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        [$uid, $slot, $old] = $this->firstTextSlot();
        $before = $this->page->published_version_id;

        $this->put(route('panou.cms.pages.update', $this->page), [
            'lock_version' => $this->page->lock_version,
            'slots_json' => json_encode([$uid => [$slot => 'Text de test modificat']]),
            'action' => 'save',
        ])->assertRedirect();

        $page = $this->page->fresh();
        $this->assertSame($before, $page->published_version_id);
        $this->assertTrue($page->hasUnpublishedChanges());
        $this->get('/kinetoterapie')->assertDontSee('Text de test modificat');

        $this->put(route('panou.cms.pages.update', $page), [
            'lock_version' => $page->lock_version, 'slots_json' => '{}', 'action' => 'publish',
        ])->assertRedirect();
        $this->get('/kinetoterapie')->assertSee('Text de test modificat');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.page.published', 'subject_id' => $page->id]);
    }

    public function test_publishing_requires_permission(): void
    {
        $role = Role::findOrCreate('editor_fara_publicare', 'web');
        $role->syncPermissions(['cms.edit']);
        $user = $this->actingAsRole('editor_fara_publicare');
        $this->put(route('panou.cms.pages.update', $this->page), ['lock_version' => $this->page->lock_version, 'slots_json' => '{}', 'action' => 'publish'])->assertForbidden();
        $this->post(route('panou.cms.pages.publish', $this->page))->assertForbidden();
        $this->assertInstanceOf(User::class, $user);
    }

    public function test_editor_input_is_sanitized(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        [$uid, $slot] = $this->firstTextSlot();
        $this->put(route('panou.cms.pages.update', $this->page), [
            'lock_version' => $this->page->lock_version,
            'slots_json' => json_encode([$uid => [$slot => '<script>alert(1)</script><b onclick="x()">sigur</b><img src=x onerror=alert(1)><?php echo 1; ?>']]),
        ])->assertRedirect();

        $html = collect($this->page->fresh()->currentVersion->blocks)->firstWhere('uid', $uid)['html'];
        $this->assertStringContainsString('<b>sigur</b>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<?php', $html);

        // Linkurile javascript: nu sunt acceptate; structura importată e curățată de atribute on*.
        $this->assertFalse(StructureSanitizer::safeUrl('javascript:alert(1)'));
        $this->assertFalse(StructureSanitizer::safeUrl(' JaVaScRiPt:alert(1)'));
        $this->assertTrue(StructureSanitizer::safeUrl('/contactus'));
        $clean = StructureSanitizer::cleanHtml('<section onmouseover="x()"><a href="javascript:x()">a</a><iframe src="https://rau.example"></iframe><script>x</script><p style="background:url(javascript:x)">t</p></section>');
        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('script', $clean);
    }

    public function test_concurrent_edit_is_rejected_without_losing_history(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        [$uid, $slot] = $this->firstTextSlot();
        $stale = $this->page->lock_version;
        $versions = PageVersion::where('page_id', $this->page->id)->count();

        // Altcineva salvează între timp.
        app(PageService::class)->saveVersion($this->page, $this->page->currentVersion->blocks, [], [], null, $stale, 'alt editor');

        $this->put(route('panou.cms.pages.update', $this->page), [
            'lock_version' => $stale,
            'slots_json' => json_encode([$uid => [$slot => 'Modificare pierdută?']]),
        ])->assertRedirect()->assertSessionHasErrors('lock_version');
        $this->assertSame($versions + 1, PageVersion::where('page_id', $this->page->id)->count());
    }

    public function test_second_editor_sees_who_is_editing(): void
    {
        $first = $this->actingAsRole(Roles::EDITOR_SITE, ['name' => 'Editor Unu']);
        $this->get(route('panou.cms.pages.edit', $this->page))->assertOk()->assertDontSee('editează această pagină acum');
        $this->actingAsRole(Roles::EDITOR_SITE, ['name' => 'Editor Doi']);
        $this->get(route('panou.cms.pages.edit', $this->page))->assertOk()->assertSee('Editor Unu editează această pagină acum');
        $this->assertNotNull($first);
    }

    public function test_versions_restore_and_immutability(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        $original = $this->page->currentVersion;
        $blocks = $original->blocks;
        $blocks[0]['hidden'] = true;
        app(PageService::class)->saveVersion($this->page, $blocks, $original->seo, $original->settings, null, $this->page->lock_version, 'ascunde');

        $this->post(route('panou.cms.pages.restore', [$this->page, $original]))->assertRedirect();
        $restored = $this->page->fresh()->currentVersion;
        $this->assertSame($original->blocks, $restored->blocks);
        $this->assertGreaterThan($original->number, $restored->number);
        $this->get(route('panou.cms.pages.versions', $this->page))->assertOk()->assertSee('Restaurat din versiunea '.$original->number);

        $this->expectException(\LogicException::class);
        $original->update(['blocks' => []]);
    }

    public function test_block_operations(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        $blocks = $this->page->currentVersion->blocks;
        $count = count($blocks);
        $template = SectionTemplate::firstOrFail();

        $this->put(route('panou.cms.pages.update', $this->page), ['lock_version' => $this->page->lock_version, 'op' => 'add:'.$blocks[0]['uid'].':'.$template->id])->assertRedirect();
        $page = $this->page->fresh();
        $this->assertCount($count + 1, $page->currentVersion->blocks);
        $this->assertSame($template->snippet, $page->currentVersion->blocks[1]['snippet']);

        $newUid = $page->currentVersion->blocks[1]['uid'];
        $this->put(route('panou.cms.pages.update', $page), ['lock_version' => $page->lock_version, 'op' => 'up:'.$newUid])->assertRedirect();
        $page = $page->fresh();
        $this->assertSame($newUid, $page->currentVersion->blocks[0]['uid']);

        $this->put(route('panou.cms.pages.update', $page), ['lock_version' => $page->lock_version, 'op' => 'delete:'.$newUid])->assertRedirect();
        $this->assertCount($count, $page->fresh()->currentVersion->blocks);
    }

    public function test_broken_internal_links_are_flagged(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->get(route('panou.cms.pages.index'))->assertOk()->assertSee('/neurologie')->assertSee('Linkuri interne către pagini inexistente');
        $this->get(route('panou.cms.menus.index'))->assertOk()->assertSee('link către pagină inexistentă');
    }

    public function test_create_page_and_reserved_paths(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        $this->post(route('panou.cms.pages.store'), ['title' => 'Neurologie', 'path' => '/neurologie'])->assertRedirect();
        $page = Page::where('path', '/neurologie')->firstOrFail();
        $this->assertSame('draft', $page->status);
        $this->get('/neurologie')->assertNotFound();

        $this->post(route('panou.cms.pages.store'), ['title' => 'X', 'path' => '/panou/x'])->assertSessionHasErrors('path');
        $this->post(route('panou.cms.pages.store'), ['title' => 'X', 'path' => '/kinetoterapie'])->assertSessionHasErrors('path');
    }

    public function test_preview_renders_draft_without_publishing(): void
    {
        $this->actingAsRole(Roles::EDITOR_SITE);
        [$uid, $slot] = $this->firstTextSlot();
        $this->put(route('panou.cms.pages.update', $this->page), ['lock_version' => $this->page->lock_version, 'slots_json' => json_encode([$uid => [$slot => 'Doar în ciornă']])]);
        $this->get(route('panou.cms.pages.preview', $this->page))->assertOk()->assertSee('Doar în ciornă')->assertSee('noindex', false);
        $this->get('/kinetoterapie')->assertDontSee('Doar în ciornă');
    }
}
