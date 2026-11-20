<?php

namespace Tests\Feature\Scheduling;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Core\Mail\RequestAlert;
use App\Core\Settings\Settings;
use App\Modules\Identity\Roles;
use App\Modules\Site\Services\FormSubmissionIntake;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Fiecare solicitare de programare de pe site pleacă și prin e-mail la clinică, fără mesajul pacientului. */
class RequestAlertTest extends TestCase
{
    use SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
        config(['notifications.real_sending_outside_production' => true, 'mail.default' => 'array']);
    }

    private function request(): PublicAppointmentRequest
    {
        return new PublicAppointmentRequest(
            'Exemplu Fictiv Ana', '0700 111 222', 'ana@demo.sevalia.invalid', 'telefon', null, null, null, $this->location->id, null,
            'Am dureri de spate de o lună (text medical)', '1990-01-01', CarbonImmutable::now(), 'https://exemplu.test/contactus', hash('sha256', '127.0.0.1'),
        );
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function sent(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    public function test_crm_request_is_emailed_to_the_default_address_without_medical_text(): void
    {
        $result = app(AppointmentIntake::class)->submit($this->request());

        $mails = $this->sent();
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertSame('office@vitim.ro', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString($result->reference, $mail->getSubject());
        $body = $mail->getTextBody();
        $this->assertStringContainsString('Exemplu Fictiv Ana', $body);
        $this->assertStringContainsString('0700 111 222', $body);
        $this->assertStringContainsString('/panou/solicitari/', $body);
        $this->assertStringContainsString('îl găsiți în panou', $body);
        $this->assertStringNotContainsString('dureri de spate', $body, 'mesajul pacientului nu pleacă prin e-mail');
        $this->assertStringNotContainsString('1990', $body, 'nici data nașterii');
    }

    public function test_addresses_are_configurable_and_empty_disables_email(): void
    {
        $this->actingAsRole(Roles::MANAGER);
        $this->get(route('panou.scheduling-settings.edit'))->assertOk()->assertSee('E-mail pentru solicitări')->assertSee('office@vitim.ro');
        $form = [];
        foreach (app(\App\Modules\Scheduling\Services\SchedulingSettings::class)->all() as $key => $value) {
            $form[str_replace('.', '_', $key)] = is_bool($value) ? (int) $value : $value;
        }
        $this->put(route('panou.scheduling-settings.update'), ['scheduling_online_alert_emails' => 'nu-e-adresa'] + $form)->assertSessionHasErrors('scheduling_online_alert_emails');
        $this->put(route('panou.scheduling-settings.update'), ['scheduling_online_alert_emails' => 'receptie@demo.sevalia.invalid, office@vitim.ro'] + $form)->assertSessionHasNoErrors();

        app(AppointmentIntake::class)->submit($this->request());
        $this->assertSame(['receptie@demo.sevalia.invalid', 'office@vitim.ro'], array_map(fn ($a) => $a->getAddress(), $this->sent()[0]->getTo()));

        app(Settings::class)->set(RequestAlert::SETTING, '');
        app(AppointmentIntake::class)->submit($this->request());
        $this->assertCount(1, $this->sent());
    }

    public function test_requests_kept_in_site_messages_are_emailed_too(): void
    {
        app(FormSubmissionIntake::class)->submit($this->request());
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('/panou/site/mesaje/', $this->sent()[0]->getTextBody());
    }

    public function test_outside_production_nothing_is_really_sent(): void
    {
        config(['notifications.real_sending_outside_production' => false]);
        $this->assertSame('log', app(RequestAlert::class)->mailer());
    }
}
