<?php

namespace Tests\Feature\Cms;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\IntakeResult;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Core\Settings\Settings;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\FormSubmission;
use App\Modules\Site\Services\FormToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicFormTest extends TestCase
{
    use ImportsSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importSite();
        RateLimiter::clear('site-form:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            FormToken::FIELD => FormToken::issue('contact', time() - 30),
            'name' => 'Pacient Fictiv',
            'contact' => ['telefon'],
            'phone' => '0700 000 000',
            'specialties' => [Specialty::where('slug', 'reumatologie')->value('id')],
            'doctors' => [StaffMember::where('display_name', 'dr. Cristina Pop')->value('id')],
            'consent' => '1',
        ], $overrides);
    }

    public function test_form_is_rendered_faithfully_without_birth_date_by_default(): void
    {
        $html = (string) $this->get('/contactus')->assertOk()->getContent();
        $this->assertStringContainsString('name="'.FormToken::FIELD.'"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('Care e nevoia ta?', $html);
        $this->assertStringNotContainsString('Data nasterii', $html);
        $this->assertStringNotContainsString('email_to', $html);
        $this->assertStringContainsString('class="s_website_form_send btn btn-secondary btn-lg"', $html);

        app(Settings::class)->set('site.contact_birthdate', true);
        $this->get('/contactus')->assertSee('Data nasterii');
    }

    public function test_valid_submission_is_stored_encrypted_and_does_not_promise_a_slot(): void
    {
        $this->post('/formular/contact', $this->payload(), ['Origin' => config('app.url')])
            ->assertStatus(303)->assertRedirect('/contactus-thank-you');

        $submission = FormSubmission::sole();
        $this->assertSame('Pacient Fictiv', $submission->payload['nume']);
        $this->assertNull($submission->payload['data_nasterii']);
        $raw = \DB::table('form_submissions')->value('payload');
        $this->assertStringNotContainsString('Pacient Fictiv', $raw);
        $this->get('/contactus-thank-you')->assertOk()->assertSee('Mulțumim!');
    }

    public function test_confirmed_intake_shows_its_message(): void
    {
        $this->app->bind(AppointmentIntake::class, fn () => new class implements AppointmentIntake
        {
            public function submit(PublicAppointmentRequest $request): IntakeResult
            {
                return new IntakeResult(true, true, 'Programare confirmată (test).', 'T1');
            }
        });
        $response = $this->post('/formular/contact', $this->payload())->assertStatus(303);
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('confirmare=', $location);
        $this->get(substr($location, strpos($location, '/contactus-thank-you')))->assertSee('Programare confirmată (test).');
    }

    public function test_spam_is_dropped_silently(): void
    {
        $this->post('/formular/contact', $this->payload(['website' => 'http://spam.example']))->assertStatus(303);
        $this->post('/formular/contact', $this->payload([FormToken::FIELD => FormToken::issue('contact')]))->assertStatus(303);
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_invalid_or_foreign_token_is_rejected(): void
    {
        $this->post('/formular/contact', $this->payload([FormToken::FIELD => 'contact.1.x.semnatura-falsa']))
            ->assertStatus(422)->assertSee('Formularul a expirat');
        $this->post('/formular/contact', $this->payload([FormToken::FIELD => FormToken::issue('emsella', time() - 30)]))->assertStatus(422);
        $this->post('/formular/contact', $this->payload(), ['Origin' => 'https://atacator.example'])->assertForbidden();
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_validation_errors_re_render_the_form_with_values(): void
    {
        $this->post('/formular/contact', $this->payload(['consent' => null, 'contact' => ['email'], 'email' => 'gresit']))
            ->assertStatus(422)
            ->assertSee('Pentru a trimite mesajul este necesar acordul')
            ->assertSee('Adresa de e-mail nu pare corectă')
            ->assertSee('value="Pacient Fictiv"', false);
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_submissions_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/formular/contact', $this->payload())->assertStatus(303);
        }
        $this->post('/formular/contact', $this->payload())->assertStatus(429)->assertSee('Ați trimis deja mai multe mesaje');
        $this->assertSame(5, FormSubmission::count());
    }

    public function test_token_round_trip(): void
    {
        $token = FormToken::issue('contact', 1000);
        $this->assertNull(FormToken::verify('contact', $token, 1010));
        $this->assertSame('prea_rapid', FormToken::verify('contact', $token, 1001));
        $this->assertSame('expirat', FormToken::verify('contact', $token, 1000 + 7201));
        $this->assertSame('token', FormToken::verify('emsella', $token, 1010));
        $this->assertSame('Programare', Crypt::decryptString(Crypt::encryptString('Programare')));
    }
}
