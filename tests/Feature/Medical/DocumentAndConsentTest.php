<?php

namespace Tests\Feature\Medical;

use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Medical\Services\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentAndConsentTest extends TestCase
{
    use MedicalFixtures;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMedical();
        $this->dir = sys_get_temp_dir().'/sevalia-docs-'.uniqid();
        config(['medical.documents_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function upload($patient, UploadedFile $file, string $category = 'rezultat')
    {
        return $this->as($this->medicUser)->post(route('panou.medical.documents.store', $patient), ['title' => 'Document fictiv', 'category' => $category, 'file' => $file]);
    }

    public function test_pdf_is_stored_encrypted_and_served_with_safe_headers(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $pdf = "%PDF-1.4\n% Conținut fictiv SECRET-123\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";
        $this->upload($patient, UploadedFile::fake()->createWithContent('rezultat analize.pdf', $pdf))->assertSessionHasNoErrors();

        $doc = MedicalDocument::firstOrFail();
        $onDisk = file_get_contents(app(DocumentStorage::class)->absolute($doc->storage_path));
        $this->assertStringNotContainsString('SECRET-123', $onDisk, 'fișierul de pe disc este criptat');
        $this->assertSame(hash('sha256', $pdf), $doc->sha256);

        $response = $this->as($this->medicUser)->get(route('panou.medical.documents.download', $doc))->assertOk();
        $this->assertSame($pdf, $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_invalid_uploads_are_rejected(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);

        // PDF cu JavaScript (inclusiv nume codificat hexazecimal).
        $this->upload($patient, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF"))
            ->assertSessionHasErrors('file');
        $this->upload($patient, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n1 0 obj << /S /#4Aava#53cript >> endobj\n%%EOF"))
            ->assertSessionHasErrors('file');
        // Extensie falsă: text redenumit .pdf.
        $this->upload($patient, UploadedFile::fake()->createWithContent('c.pdf', 'doar text, nu e PDF'))->assertSessionHasErrors('file');
        // Tip nepermis.
        $this->upload($patient, UploadedFile::fake()->createWithContent('d.html', '<script>alert(1)</script>'))->assertSessionHasErrors('file');
        // Prea mare.
        config(['medical.max_upload_kb' => 1]);
        $this->upload($patient, UploadedFile::fake()->createWithContent('e.pdf', "%PDF-1.4\n".str_repeat('x', 4096)))->assertSessionHasErrors('file');

        $this->assertSame(0, MedicalDocument::count());
    }

    public function test_images_are_reencoded(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $jpeg = ob_get_clean()."\xFF\xD9COMENTARIU-ASCUNS";
        $this->upload($patient, UploadedFile::fake()->createWithContent('poza.jpg', $jpeg))->assertSessionHasNoErrors();

        $doc = MedicalDocument::firstOrFail();
        $plain = app(DocumentStorage::class)->read($doc);
        $this->assertStringNotContainsString('COMENTARIU-ASCUNS', $plain);
        $this->assertSame([40, 30], array_slice(getimagesizefromstring($plain), 0, 2));
    }

    public function test_retracting_a_document_keeps_it_in_history(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $this->upload($patient, UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF"));
        $doc = MedicalDocument::firstOrFail();
        $this->as($this->medicUser)->post(route('panou.medical.documents.retract', $doc), ['reason' => 'Încărcat la alt pacient (test)'])->assertSessionHasNoErrors();
        $this->assertSame('retras', $doc->fresh()->status);
        $this->expectException(\LogicException::class);
        $doc->delete();
    }

    public function test_consent_requires_published_text_and_scan_for_paper(): void
    {
        $patient = $this->patient();
        $this->careVia($patient, $this->medic);
        $template = ConsentTemplate::create(['name' => 'Model test']);
        $version = ConsentTemplateVersion::create(['consent_template_id' => $template->id, 'version' => 1, 'body' => 'Text fictiv al clinicii pentru test.', 'published_at' => now()]);

        $base = ['consent_template_version_id' => $version->id, 'given_by' => 'pacient', 'given_at' => now()->subMinute()->format('Y-m-d H:i')];
        $this->as($this->medicUser)->post(route('panou.medical.consents.store', $patient), $base + ['method' => 'hartie_scanat'])->assertSessionHasErrors('medical_document_id');
        $this->as($this->medicUser)->post(route('panou.medical.consents.store', $patient), $base + ['method' => 'verbal_consemnat'])->assertSessionHasNoErrors();

        $consent = PatientConsent::firstOrFail();
        $this->assertSame($version->id, $consent->consent_template_version_id);

        // Un text nou nu schimbă consimțământul deja dat.
        $this->as($this->medicUser)->get(route('panou.patients.show', [$patient, 'tab' => 'consimtaminte']))->assertOk()->assertSee('Model test');
        $this->as($this->medicUser)->post(route('panou.medical.consents.withdraw', $consent), ['reason' => 'Pacientul și-a retras acordul (test)']);
        $this->assertNotNull($consent->fresh()->withdrawn_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.consent_withdrawn', 'patient_id' => $patient->id]);
    }
}
