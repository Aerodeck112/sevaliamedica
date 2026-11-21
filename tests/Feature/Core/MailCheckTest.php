<?php

namespace Tests\Feature\Core;

use App\Core\Mail\MailSettings;
use App\Core\Mail\RequestAlert;
use App\Core\Settings\Settings;
use App\Modules\Identity\Roles;
use Tests\TestCase;

/** Administrare → Verificare e-mail: diagnostic, SMTP din panou și e-mail de test. */
class MailCheckTest extends TestCase
{
    public function test_page_explains_why_mails_do_not_leave(): void
    {
        config(['sevalia.stage' => 'staging', 'notifications.real_sending_outside_production' => false]);
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->get(route('panou.mail-check'))->assertOk()
            ->assertSee('E-mailurile nu pleacă')->assertSee('modul producție')->assertSee('office@vitim.ro');
    }

    public function test_smtp_from_panel_is_applied_and_password_is_encrypted(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->withRecentReauth()->put(route('panou.mail-check.smtp'), [
            'host' => 'mail.demo.sevalia.invalid', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'office@demo.sevalia.invalid', 'password' => 'parola-secreta-demo', 'from_address' => 'office@demo.sevalia.invalid', 'from_name' => 'SEVALIA',
        ])->assertSessionHasNoErrors();

        $stored = app(Settings::class)->get(MailSettings::SETTING);
        $this->assertStringNotContainsString('parola-secreta-demo', json_encode($stored));
        app(MailSettings::class)->apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.demo.sevalia.invalid', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('parola-secreta-demo', config('mail.mailers.smtp.password'));
        $this->assertSame('office@demo.sevalia.invalid', config('mail.from.address'));
        $this->get(route('panou.mail-check'))->assertOk()->assertDontSee('parola-secreta-demo');
    }

    public function test_test_email_is_sent_and_logged(): void
    {
        config(['sevalia.stage' => 'production', 'mail.default' => 'array']);
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->post(route('panou.mail-check.test'), ['to' => 'test@demo.sevalia.invalid'])->assertSessionHasNoErrors();

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Test e-mail SEVALIA', $messages->first()->getOriginalMessage()->getSubject());
        $this->assertTrue(app(Settings::class)->get(RequestAlert::LOG)[0]['ok']);
        $this->get(route('panou.mail-check'))->assertSee('predat serverului');
    }

    public function test_reception_cannot_open_it(): void
    {
        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.mail-check'))->assertForbidden();
    }
}
