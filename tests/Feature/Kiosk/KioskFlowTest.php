<?php

namespace Tests\Feature\Kiosk;

use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Kiosk\Http\Middleware\KioskDeviceAuth;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\KioskSession;
use App\Modules\Kiosk\Models\PatientReview;
use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Medical\Services\DocumentStorage;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Support\Facades\File;
use Tests\Feature\Medical\MedicalFixtures;
use Tests\TestCase;

class KioskFlowTest extends TestCase
{
    use MedicalFixtures;

    private User $receptie;

    private User $manager;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMedical();
        $this->receptie = User::factory()->withMfa()->create();
        $this->receptie->assignRole(Roles::RECEPTIE);
        $this->manager = User::factory()->withMfa()->create();
        $this->manager->assignRole(Roles::MANAGER);
        $this->dir = sys_get_temp_dir().'/sevalia-kiosk-'.uniqid();
        config(['medical.documents_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Asociază o tabletă și întoarce tokenul ei (cookie-ul trimis ulterior de tabletă). */
    private function pairedTablet(string $mode = 'ambele'): array
    {
        $this->as($this->manager)->post(route('panou.kiosk.devices.store'), ['name' => 'Tableta test', 'mode' => $mode])->assertRedirect();
        $code = str_replace('-', '', session('pairing')['code']);
        $device = KioskDevice::latest('id')->first();
        $this->app['auth']->forgetGuards();
        $response = $this->post(route('kiosk.pair.store'), ['pairing_code' => $code])->assertRedirect(route('kiosk.home'));
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === KioskDeviceAuth::COOKIE);
        $this->assertNotNull($cookie);

        return [$device->fresh(), $cookie->getValue()];
    }

    private function consentVersion(): ConsentTemplateVersion
    {
        $template = ConsentTemplate::create(['name' => 'Consimțământ fictiv pentru kinetoterapie', 'is_active' => true]);

        return ConsentTemplateVersion::create(['consent_template_id' => $template->id, 'version' => 1, 'body' => "Text fictiv de consimțământ.\nAl doilea paragraf.", 'published_at' => now()]);
    }

    private function signaturePng(): string
    {
        $img = imagecreatetruecolor(600, 180);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 14, 59, 61);
        imagesetthickness($img, 4);
        for ($i = 0; $i < 12; $i++) {
            imageline($img, 30 + $i * 40, 120 - ($i % 3) * 30, 70 + $i * 40, 60 + ($i % 2) * 50, $ink);
        }
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_consent_is_signed_on_tablet_with_six_digit_code(): void
    {
        [$device, $token] = $this->pairedTablet('consimtamant');
        $patient = $this->patient();
        $version = $this->consentVersion();

        $this->as($this->receptie)->post(route('panou.kiosk.sessions.consent', $patient), ['consent_template_version_id' => $version->id])->assertRedirect();
        $code = session('kiosk_code')['code'];
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, KioskSession::first()->code_hash, 'codul nu se păstrează în clar');

        $this->app['auth']->forgetGuards();
        $tablet = $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, $token);
        $tablet->get(route('kiosk.home'))->assertOk()->assertSee('Introduceți codul de 6 cifre');
        $tablet->post(route('kiosk.code'), ['code' => $code])->assertRedirect(route('kiosk.request'));
        $tablet->get(route('kiosk.request'))->assertOk()->assertSee('Consimțământ fictiv pentru kinetoterapie')->assertSee('Text fictiv de consimțământ.')
            ->assertDontSee($patient->last_name.' '.$patient->first_name);

        $tablet->post(route('kiosk.consent'), ['action' => 'semnez', 'signature' => $this->signaturePng()])->assertSessionHasErrors('agree');
        $tablet->post(route('kiosk.consent'), ['action' => 'semnez', 'agree' => '1', 'signature' => $this->signaturePng()])->assertRedirect(route('kiosk.thanks', ['tip' => 'consimtamant']));

        $consent = PatientConsent::sole();
        $this->assertSame('semnat_tableta', $consent->method);
        $this->assertSame($version->id, $consent->consent_template_version_id);
        $this->assertStringContainsString(hash('sha256', $version->body), $consent->notes, 'amprenta textului semnat');
        $document = MedicalDocument::findOrFail($consent->medical_document_id);
        $this->assertSame('image/png', $document->mime);
        $this->assertSame(hash('sha256', app(DocumentStorage::class)->read($document)), $document->sha256);
        $this->assertSame('finalizat', KioskSession::sole()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk.consent_signed', 'patient_id' => $patient->id]);

        // Codul nu se poate refolosi.
        $tablet->post(route('kiosk.code'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_patient_can_refuse_consent(): void
    {
        [, $token] = $this->pairedTablet();
        $patient = $this->patient();
        $this->as($this->receptie)->post(route('panou.kiosk.sessions.consent', $patient), ['consent_template_version_id' => $this->consentVersion()->id]);
        $code = session('kiosk_code')['code'];
        $this->app['auth']->forgetGuards();
        $tablet = $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, $token);
        $tablet->post(route('kiosk.code'), ['code' => $code]);
        $tablet->post(route('kiosk.consent'), ['action' => 'refuz'])->assertRedirect(route('kiosk.thanks', ['tip' => 'refuz']));
        $this->assertSame('refuzat', KioskSession::sole()->status);
        $this->assertSame(0, PatientConsent::count());
    }

    public function test_wrong_codes_lock_the_tablet_and_unpaired_devices_are_rejected(): void
    {
        $this->get(route('kiosk.home'))->assertRedirect(route('kiosk.pair'));
        $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, 'token-inventat')->get(route('kiosk.home'))->assertRedirect(route('kiosk.pair'));

        [$device, $token] = $this->pairedTablet();
        $tablet = $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, $token);
        for ($i = 0; $i < 5; $i++) {
            $tablet->post(route('kiosk.code'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $tablet->post(route('kiosk.code'), ['code' => '000000'])->assertSessionHasErrors(['code' => 'Prea multe coduri greșite. Tableta se deblochează în 5 minute.']);

        $this->as($this->manager)->post(route('panou.kiosk.devices.revoke', $device))->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, $token)->get(route('kiosk.home'))->assertRedirect(route('kiosk.pair'));
    }

    public function test_review_after_service_with_stars_and_auto_open(): void
    {
        [$device, $token] = $this->pairedTablet('recenzie');
        $patient = $this->patient();
        $record = ServiceRecord::create([
            'patient_id' => $patient->id, 'service_id' => $this->consult->id, 'staff_member_id' => $this->medic->id,
            'location_id' => $this->location->id, 'performed_at' => now(), 'payer_type' => 'privat', 'manual_reason' => 'test',
        ]);

        $this->as($this->receptie)->post(route('panou.kiosk.sessions.review', $record), ['kiosk_device_id' => $device->id, 'auto_open' => 1])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $tablet = $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, $token);
        $tablet->withCredentials()->getJson(route('kiosk.poll'))->assertJson(['open' => route('kiosk.request')]);
        $tablet->get(route('kiosk.request'))->assertOk()->assertSee($this->consult->name)->assertSee($this->medic->display_name)->assertSee('★');
        $tablet->post(route('kiosk.review'), ['rating' => 9])->assertSessionHasErrors('rating');
        $tablet->post(route('kiosk.review'), ['rating' => 5, 'comment' => 'Totul a fost foarte bine (text fictiv).', 'publish_consent' => 1])->assertRedirect();

        $review = PatientReview::sole();
        $this->assertSame(5, $review->rating);
        $this->assertSame($this->medic->id, $review->staff_member_id);
        $this->assertStringNotContainsString('foarte bine', (string) \DB::table('patient_reviews')->value('comment'), 'comentariul e criptat');

        // A doua cerere pentru același serviciu e refuzată.
        $this->as($this->receptie)->post(route('panou.kiosk.sessions.review', $record))->assertSessionHasErrors('service_record');

        // Statistici și publicare (doar cu acordul pacientului).
        $this->as($this->manager)->get(route('panou.kiosk.reviews'))->assertOk()->assertSee($this->medic->display_name)->assertSee('Totul a fost foarte bine');
        $this->as($this->manager)->post(route('panou.kiosk.reviews.publish', $review), ['published' => 1])->assertRedirect();
        $this->assertTrue($review->fresh()->published);
    }

    public function test_review_without_publish_consent_cannot_be_published_and_reception_cannot_see_reviews(): void
    {
        $review = PatientReview::create(['patient_id' => $this->patient()->id, 'rating' => 2, 'comment' => 'fictiv', 'publish_consent' => false]);
        $this->as($this->receptie)->get(route('panou.kiosk.reviews'))->assertForbidden();
        $this->as($this->manager)->post(route('panou.kiosk.reviews.publish', $review), ['published' => 1])->assertStatus(422);
        $this->assertFalse($review->fresh()->published);
    }

    public function test_device_mode_is_enforced(): void
    {
        [$device, $token] = $this->pairedTablet('recenzie');
        $patient = $this->patient();
        $this->as($this->receptie)->post(route('panou.kiosk.sessions.consent', $patient), ['consent_template_version_id' => $this->consentVersion()->id, 'kiosk_device_id' => $device->id])->assertStatus(422);
        $this->as($this->receptie)->post(route('panou.kiosk.sessions.consent', $patient), ['consent_template_version_id' => ConsentTemplateVersion::first()->id]);
        $code = session('kiosk_code')['code'];
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(KioskDeviceAuth::COOKIE, $token)->post(route('kiosk.code'), ['code' => $code])->assertSessionHasErrors('code');
    }
}
