<?php

namespace Tests\Feature\Identity;

use App\Core\Audit\AuditLog;
use App\Models\User;
use App\Modules\Identity\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_panel_requires_authentication(): void
    {
        $this->get('/panou')->assertRedirect(route('login'));
    }

    public function test_login_with_mfa_requires_valid_totp(): void
    {
        $user = User::factory()->withMfa(self::SECRET)->create(['email' => 'receptie@example.test']);
        $user->assignRole(Roles::RECEPTIE);

        $this->post(route('login.attempt'), ['email' => 'receptie@example.test', 'password' => 'parola-test-123'])
            ->assertRedirect(route('mfa.challenge'));

        // Fără MFA trecut, panoul redirecționează către cod.
        $this->get('/panou')->assertRedirect(route('mfa.challenge'));

        $this->post(route('mfa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');

        $code = (new Google2FA)->getCurrentOtp(self::SECRET);
        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('panou.dashboard'));
        $this->get('/panou')->assertOk()->assertSee('Bună ziua');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_totp_code_cannot_be_reused(): void
    {
        $user = User::factory()->withMfa(self::SECRET)->create();
        $code = (new Google2FA)->getCurrentOtp(self::SECRET);
        $mfa = app(\App\Modules\Identity\Services\Mfa::class);

        $this->assertTrue($mfa->verify($user, self::SECRET, $code));
        $this->assertFalse($mfa->verify($user, self::SECRET, $code));
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = User::factory()->withMfa(self::SECRET)->create();
        $mfa = app(\App\Modules\Identity\Services\Mfa::class);

        $this->assertTrue($mfa->useRecoveryCode($user, 'aaaa-bbbb-cccc'));
        $this->assertFalse($mfa->useRecoveryCode($user->fresh(), 'AAAA-BBBB-CCCC'));
    }

    public function test_user_without_mfa_is_forced_to_enroll(): void
    {
        $user = User::factory()->create(['email' => 'nou@example.test']);
        $user->assignRole(Roles::MEDIC);

        $this->post(route('login.attempt'), ['email' => 'nou@example.test', 'password' => 'parola-test-123'])
            ->assertRedirect(route('panou.mfa.setup'));
        $this->get('/panou')->assertRedirect(route('panou.mfa.setup'));
        $this->get(route('panou.mfa.setup'))->assertOk()->assertSee('Autentificare în doi pași');
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create(['email' => 'tinta@example.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), ['email' => 'tinta@example.test', 'password' => 'gresit'])->assertSessionHasErrors('email');
        }
        $this->post(route('login.attempt'), ['email' => 'tinta@example.test', 'password' => 'parola-test-123'])
            ->assertSessionHasErrors(['email' => 'Prea multe încercări. Reîncercați peste 15 minute.']);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_throttled']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'inactiv@example.test']);
        $this->post(route('login.attempt'), ['email' => 'inactiv@example.test', 'password' => 'parola-test-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_reset_token_is_single_use_and_revokes_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        DB::table('sessions')->insert(['id' => 'veche', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'reset@example.test', 'password' => 'ParolaNoua2026', 'password_confirmation' => 'ParolaNoua2026'];

        $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
        $this->assertDatabaseMissing('sessions', ['id' => 'veche']);
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
    }

    public function test_password_reset_request_does_not_reveal_accounts(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'nu-exista@example.test'])
            ->assertSessionHas('status', 'Dacă adresa aparține unui cont activ, veți primi un e-mail cu instrucțiuni.');
    }

    public function test_absolute_session_lifetime_is_enforced(): void
    {
        $user = User::factory()->withMfa()->create();
        $user->assignRole(Roles::RECEPTIE);
        $this->actingAs($user)->withSession(['auth.started_at' => time() - 13 * 3600, 'mfa.passed' => true])
            ->get('/panou')->assertRedirect(route('login'));
    }

    public function test_panel_responses_are_not_cacheable_and_noindex(): void
    {
        $this->actingAsRole(Roles::RECEPTIE);
        $response = $this->get('/panou')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString("script-src 'self' 'nonce-", $response->headers->get('Content-Security-Policy'));
    }

    public function test_audit_log_is_append_only_and_sanitized(): void
    {
        $log = \App\Core\Audit\Audit::log('test.event', context: ['cnp' => '1900101123456', 'password' => 'x', 'ok' => 'da']);
        $this->assertSame('[eliminat]', $log->context['cnp']);
        $this->assertSame('[eliminat]', $log->context['password']);
        $this->assertSame('da', $log->context['ok']);

        $this->expectException(\LogicException::class);
        AuditLog::first()->update(['action' => 'altceva']);
    }
}
