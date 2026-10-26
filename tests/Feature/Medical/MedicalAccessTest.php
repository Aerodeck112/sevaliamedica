<?php

namespace Tests\Feature\Medical;

use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Medical\Models\ClinicalAlert;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Services\RecordService;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BookingRequest;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Scenariul 4: acces interzis între roluri și la documente neautorizate. */
class MedicalAccessTest extends TestCase
{
    use MedicalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMedical();
        config(['medical.documents_path' => sys_get_temp_dir().'/sevalia-test-docs-'.getmypid()]);
    }

    private function seedRecordAndDocument(): array
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $record = app(RecordService::class)->createDraft($patient, null, $this->medicUser, ['kind' => 'nota']);
        $this->as($this->medicUser)->post(route('panou.medical.documents.store', $patient), [
            'title' => 'Rezultat fictiv', 'category' => 'rezultat',
            'file' => UploadedFile::fake()->createWithContent('rezultat.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF"),
        ])->assertSessionHasNoErrors();

        return [$patient, $record, MedicalDocument::latest('id')->firstOrFail()];
    }

    public function test_roles_without_care_or_medical_permissions_are_refused(): void
    {
        [$patient, $record, $document] = $this->seedRecordAndDocument();

        $receptie = User::factory()->withMfa()->create();
        $receptie->assignRole(Roles::RECEPTIE);
        $admin = User::factory()->withMfa()->create();
        $admin->assignRole(Roles::ADMIN_TEHNIC);

        foreach ([$receptie, $admin, $this->therapistUser] as $user) {
            $this->as($user)->get(route('panou.medical.records.show', $record))->assertForbidden();
            $this->as($user)->get(route('panou.medical.documents.download', $document))->assertForbidden();
            $this->as($user)->get(route('panou.medical.documents.show', $document))->assertForbidden();
            $this->as($user)->post(route('panou.medical.alerts.store', $patient), ['severity' => 'info', 'text' => 'Nu are voie'])->assertForbidden();
        }

        // Recepția vede fișa administrativă, dar nu și taburile medicale.
        $this->as($receptie)->get(route('panou.patients.show', $patient))->assertOk()->assertDontSee('Fișe medicale')->assertDontSee('Consimțăminte');
        $this->as($receptie)->get(route('panou.patients.show', [$patient, 'tab' => 'documente']))->assertOk()->assertDontSee('Rezultat fictiv');

        // Medicul cu relație de îngrijire are acces, auditat.
        $this->as($this->medicUser)->get(route('panou.medical.documents.download', $document))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.document_downloaded', 'subject_id' => $document->id, 'patient_id' => $patient->id]);
    }

    public function test_document_of_another_patient_is_not_reachable_by_guessing_ids(): void
    {
        [, , $document] = $this->seedRecordAndDocument();
        // Medicul are relație doar cu un alt pacient.
        $other = \App\Models\User::factory()->withMfa()->create();
        $other->assignRole(Roles::MEDIC);
        $staff = $this->staff('dr. Altul', 'medic', $other);
        $this->careVia($this->patient(), $staff);

        $this->as($other)->get(route('panou.medical.documents.download', $document))->assertForbidden();
        $this->as($other)->get(route('panou.medical.documents.download', $document->id + 999))->assertNotFound();
    }

    public function test_care_from_appointment_and_break_glass_are_audited(): void
    {
        [$patient, $record] = $this->seedRecordAndDocument();
        $other = User::factory()->withMfa()->create();
        $other->assignRole(Roles::MEDIC);
        $staff = $this->staff('dr. Urgență', 'medic', $other);

        $this->as($other)->get(route('panou.medical.records.show', $record))->assertForbidden();
        $this->as($other)->post(route('panou.patients.break-glass.store', $patient), ['reason' => 'Pacient adus de urgență, fără medic curant prezent'])->assertRedirect();
        $this->as($other)->get(route('panou.medical.records.show', $record))->assertOk()->assertSee('acces de urgență');
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.record_viewed', 'user_id' => $other->id, 'patient_id' => $patient->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patients.break_glass', 'user_id' => $other->id]);

        // Relația prin programare: un alt terapeut cu programare la pacient vede fișa.
        $t = User::factory()->withMfa()->create();
        $t->assignRole(Roles::TERAPEUT);
        $ts = $this->staff('Terapeut Programat', 'terapeut', $t);
        app(BookingService::class)->book(new BookingRequest($patient->id, $this->gymSession->id, $ts->id, $this->location->id, $this->at('11:00', 3)), null);
        $this->as($t)->get(route('panou.medical.records.show', $record))->assertOk();
    }

    public function test_clinical_alert_appears_in_calendar_only_for_care_team(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        ClinicalAlert::create(['patient_id' => $patient->id, 'severity' => 'critic', 'text' => 'Alergie fictivă la X', 'created_by' => $this->medicUser->id]);
        $at = $this->at('10:00', 1);
        app(BookingService::class)->book(new BookingRequest($patient->id, $this->consult->id, $this->medic->id, $this->location->id, $at), null);

        $receptie = User::factory()->withMfa()->create();
        $receptie->assignRole(Roles::RECEPTIE);
        $url = route('panou.calendar', ['date' => $at->toDateString(), 'location_id' => $this->location->id]);

        $this->as($this->medicUser)->get($url)->assertOk()->assertSee('Avertizare clinică')->assertSee('Alergie fictivă la X');
        $this->as($receptie)->get($url)->assertOk()->assertDontSee('Avertizare clinică')->assertDontSee('Alergie fictivă');
    }
}
