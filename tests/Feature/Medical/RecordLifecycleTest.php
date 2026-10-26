<?php

namespace Tests\Feature\Medical;

use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Models\MedicalTemplateVersion;
use App\Modules\Medical\Services\RecordService;
use App\Modules\Medical\Support\TemplateSchema;
use LogicException;
use Tests\TestCase;

/** Scenariul 5: finalizarea și corectarea trasabilă a unei fișe. */
class RecordLifecycleTest extends TestCase
{
    use MedicalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMedical();
    }

    public function test_draft_finalize_and_traceable_rectification(): void
    {
        $patient = $this->patient();
        $this->careViaAppointment($patient, $this->medic);
        $template = $this->template();

        // Creare draft prin interfață.
        $this->as($this->medicUser)->post(route('panou.medical.records.store', $patient), ['template_id' => $template->id, 'kind' => 'consultatie'])
            ->assertRedirect();
        $record = MedicalRecord::firstOrFail();
        $this->assertTrue($record->isDraft());

        // Salvare cu blocare optimistă; o versiune veche e refuzată.
        $this->as($this->medicUser)->put(route('panou.medical.records.update', $record), ['version' => 1, 'values' => ['motiv' => 'Text fictiv', 'valoare' => '12,5', 'optiune' => 'doi']])
            ->assertSessionHasNoErrors();
        $this->as($this->medicUser)->put(route('panou.medical.records.update', $record), ['version' => 1, 'values' => ['motiv' => 'Altă fereastră']])
            ->assertSessionHasErrors('version');
        $this->assertSame(12.5, $record->fresh()->content['valoare']);

        // Finalizarea cere reconfirmarea identității.
        $this->as($this->medicUser)->post(route('panou.medical.records.finalize', $record), ['confirm' => 1])->assertRedirect(route('panou.reauth'));
        $this->as($this->medicUser, reauth: true)->post(route('panou.medical.records.finalize', $record), ['confirm' => 1])
            ->assertRedirect(route('panou.medical.records.show', $record));
        $record->refresh();
        $this->assertTrue($record->isFinal());
        $this->assertTrue($record->integrityOk());
        $hash = $record->content_hash;

        // Conținutul finalizat nu se mai modifică: nici prin interfață, nici prin model.
        $this->as($this->medicUser)->put(route('panou.medical.records.update', $record), ['version' => 2, 'values' => ['motiv' => 'Modificare interzisă']])
            ->assertSessionHasErrors('status');
        try {
            $record->update(['content' => ['motiv' => 'x']]);
            $this->fail('Modificarea unei fișe finalizate trebuie refuzată.');
        } catch (LogicException) {
        }
        try {
            $record->delete();
            $this->fail('Fișele nu se șterg.');
        } catch (LogicException) {
        }

        // Rectificare: fișă nouă legată, cu motiv; originalul rămâne identic.
        $this->as($this->medicUser)->post(route('panou.medical.records.amend', $record), ['kind' => 'rectificare', 'reason' => 'Valoare introdusă greșit (test)'])->assertRedirect();
        $rect = MedicalRecord::where('amends_id', $record->id)->firstOrFail();
        $this->assertSame('rectificare', $rect->kind);
        $this->assertSame('Text fictiv', $rect->content['motiv'], 'rectificarea pornește de la valorile originale');
        $this->as($this->medicUser)->put(route('panou.medical.records.update', $rect), ['version' => 1, 'values' => ['motiv' => 'Text fictiv', 'valoare' => '13']]);
        $this->as($this->medicUser, reauth: true)->post(route('panou.medical.records.finalize', $rect), ['confirm' => 1]);

        $record->refresh();
        $this->assertSame($hash, $record->content_hash);
        $this->assertSame(12.5, $record->content['valoare']);
        $this->assertSame($rect->id, $record->latestRectification()->id);
        $this->assertEquals(13, $record->latestRectification()->content['valoare']);

        // Istoricul complet e vizibil pe fișa originală.
        $this->as($this->medicUser)->get(route('panou.medical.records.show', $record))
            ->assertOk()->assertSee('rectificată')->assertSee('Valoare introdusă greșit (test)')->assertSee('Nu este o semnătură electronică calificată', false);

        foreach (['medical.record_created', 'medical.record_finalized', 'medical.amendment_started', 'medical.record_amended', 'medical.record_viewed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'patient_id' => $patient->id]);
        }
        // Auditul nu conține conținut medical.
        $this->assertDatabaseMissing('audit_logs', ['context' => json_encode(['motiv' => 'Text fictiv'])]);
    }

    public function test_required_fields_block_finalization_and_only_author_edits(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $record = app(RecordService::class)->createDraft($patient, $this->template(), $this->medicUser);

        $this->as($this->medicUser, reauth: true)->post(route('panou.medical.records.finalize', $record), ['confirm' => 1])
            ->assertSessionHasErrors('values.motiv');
        $this->assertTrue($record->fresh()->isDraft());

        // Alt medic, cu relație de îngrijire, nu poate edita draftul autorului.
        $other = \App\Models\User::factory()->withMfa()->create();
        $other->assignRole(\App\Modules\Identity\Roles::MEDIC);
        $staff = $this->staff('dr. Alt Test', 'medic', $other);
        $this->careVia($patient, $staff);
        $this->as($other)->put(route('panou.medical.records.update', $record), ['version' => 1, 'values' => ['motiv' => 'x']])->assertForbidden();
    }

    public function test_record_keeps_its_template_version(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $template = $this->template();
        $record = app(RecordService::class)->createDraft($patient, $template, $this->medicUser);

        // Publicăm o versiune nouă a șablonului cu alt câmp.
        $this->as($this->medicUser)->put(route('panou.medical.templates.update', $template), [
            'name' => 'Șablon test',
            'sections' => [['title' => 'Nou', 'order' => 1, 'fields' => [['label' => 'Câmp nou', 'type' => 'text', 'order' => 1]]]],
        ])->assertSessionHasNoErrors();
        $this->as($this->medicUser)->post(route('panou.medical.templates.publish', $template))->assertSessionHasNoErrors();

        $this->assertSame(2, MedicalTemplateVersion::where('medical_template_id', $template->id)->max('version'));
        $this->assertSame('motiv', TemplateSchema::fields($record->fresh()->schema())[0]['key'], 'fișa existentă rămâne pe v1');
        $new = app(RecordService::class)->createDraft($patient, $template->fresh('latestVersion'), $this->medicUser);
        $this->assertSame('camp_nou', TemplateSchema::fields($new->schema())[0]['key']);

        $this->expectException(LogicException::class);
        MedicalTemplateVersion::first()->update(['notes' => 'x']);
    }

    public function test_template_schema_validation_messages(): void
    {
        try {
            TemplateSchema::normalize(['sections' => [['title' => 'A', 'fields' => [['label' => 'Listă', 'type' => 'select', 'options' => 'doar una']]]]]);
            $this->fail('O listă cu o singură opțiune trebuie refuzată.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('cel puțin două opțiuni', collect($e->errors())->flatten()->first());
        }
        $schema = TemplateSchema::normalize(['sections' => [['title' => 'A', 'fields' => [['label' => 'S', 'type' => 'scale', 'min' => 0, 'max' => 5], ['label' => 'D', 'type' => 'date']]]]]);
        $this->assertSame(['s' => 3, 'd' => '2026-02-28'], TemplateSchema::values($schema, ['s' => '3', 'd' => '2026-02-28'], strict: false));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        TemplateSchema::values($schema, ['s' => '9', 'd' => '2026-02-30'], strict: false);
    }
}
