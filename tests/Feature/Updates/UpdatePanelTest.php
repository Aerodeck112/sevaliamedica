<?php

namespace Tests\Feature\Updates;

use App\Modules\Identity\Roles;
use App\Modules\Updates\Models\UpdateRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsTestPackages;
use Tests\Concerns\UsesIsolatedDeployment;
use Tests\TestCase;

class UpdatePanelTest extends TestCase
{
    use BuildsTestPackages, UsesIsolatedDeployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedDeployment();
        $this->setUpTestPackages();
    }

    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            app()->maintenanceMode()->deactivate();
        }
        $this->tearDownTestPackages();
        $this->tearDownIsolatedDeployment();
        parent::tearDown();
    }

    public function test_only_technical_admin_sees_updates_and_backups(): void
    {
        foreach ([Roles::MANAGER, Roles::MEDIC, Roles::RECEPTIE, Roles::RESPONSABIL_CAS] as $role) {
            $this->actingAsRole($role);
            $this->get(route('panou.updates.index'))->assertForbidden();
            $this->get(route('panou.backups.index'))->assertForbidden();
        }
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->get(route('panou.updates.index'))->assertOk()->assertSee('Încarcă un pachet')->assertSee('0.1.0');
        $this->get(route('panou.backups.index'))->assertOk()->assertSee('Creează un backup acum');
        $this->get(route('panou.updates.environment'))->assertOk()->assertSee('pdo_mysql');
    }

    public function test_upload_requires_reauthentication(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $file = new UploadedFile($this->buildPackage('0.2.0'), 'sevalia-0.2.0-update.zip', 'application/zip', null, true);
        $this->post(route('panou.updates.upload'), ['package' => $file])->assertRedirect(route('panou.reauth'));
        $this->assertSame(0, UpdateRun::count());
    }

    public function test_full_update_through_the_panel(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $file = new UploadedFile($this->buildPackage('0.2.0'), 'sevalia-0.2.0-update.zip', 'application/zip', null, true);
        $response = $this->withRecentReauth()->post(route('panou.updates.upload'), ['package' => $file]);
        $run = UpdateRun::firstOrFail();
        $response->assertRedirect(route('panou.updates.show', $run->uuid));

        $this->get(route('panou.updates.show', $run->uuid))->assertOk()->assertSee('Instalează versiunea 0.2.0')->assertSee('Modificare fictivă de test');

        $this->withRecentReauth()->post(route('panou.updates.start', $run->uuid))->assertSessionHasErrors('confirm');
        $this->withRecentReauth()->post(route('panou.updates.start', $run->uuid), ['confirm' => '1'])->assertRedirect();

        for ($i = 0; $i < 40 && $run->refresh()->status === 'running'; $i++) {
            $this->post(route('panou.updates.advance', $run->uuid))->assertRedirect(route('panou.updates.show', $run->uuid));
            // În mentenanță, tabul Actualizări rămâne accesibil.
            $this->get(route('panou.updates.show', $run->uuid))->assertOk();
        }
        $this->assertSame('completed', $run->refresh()->status, (string) $run->error);
        $this->get(route('panou.updates.index'))->assertSee('Instalat');
    }

    public function test_rejected_package_shows_reason(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $file = new UploadedFile($this->buildPackage('0.2.0', key: $this->untrustedSecretKey), 'p.zip', 'application/zip', null, true);
        $this->withRecentReauth()->post(route('panou.updates.upload'), ['package' => $file])
            ->assertSessionHasErrors(['package' => 'Pachet respins: Pachetul este semnat cu o cheie care nu este de încredere ('.substr(hash('sha256', sodium_crypto_sign_publickey_from_secretkey($this->untrustedSecretKey)), 0, 16).').']);
    }

    public function test_dangerous_recovery_action_requires_typed_confirmation(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $run = app(\App\Modules\Updates\Updater\UpdateRunner::class);
        $zip = $this->buildPackage('0.2.0', ['database/migrations/2026_10_20_000001_esueaza.php' => self::migration("throw new \\RuntimeException('eșec test');")]);
        $update = $run->runToEnd($run->start($run->prepare($zip)));
        $this->assertSame('failed', $update->status);

        $this->get(route('panou.updates.show', $update->uuid))->assertOk()->assertSee('Restaurează baza de date din backupul de dinainte');
        $this->withRecentReauth()->post(route('panou.updates.recover', $update->uuid), ['action' => 'restore_db'])->assertSessionHasErrors('confirm_text');
        $this->withRecentReauth()->post(route('panou.updates.recover', $update->uuid), ['action' => 'restore_db', 'confirm_text' => 'CONFIRM'])->assertRedirect();
        for ($i = 0; $i < 20 && ($update->refresh()->steps['restore_db']['status'] ?? null) === 'running'; $i++) {
            $this->post(route('panou.updates.restore.continue', $update->uuid));
        }
        $this->assertSame('rolled_back', $update->refresh()->status);
    }

    public function test_backup_from_panel_and_download_is_audited(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->withRecentReauth()->post(route('panou.backups.store'), ['type' => 'database'])->assertRedirect();
        $backup = \App\Modules\Backup\Models\Backup::firstOrFail();
        for ($i = 0; $i < 20 && $backup->refresh()->status === 'running'; $i++) {
            $this->post(route('panou.backups.advance', $backup->uuid));
        }
        $this->assertSame('verified', $backup->refresh()->status);
        $this->withSession(['auth.started_at' => time(), 'mfa.passed' => true, 'auth.reauth_at' => time() - 3600])
            ->get(route('panou.backups.download', $backup->uuid))->assertRedirect(route('panou.reauth'));
        $this->withRecentReauth()->get(route('panou.backups.download', $backup->uuid))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded', 'subject_id' => $backup->id]);
    }

    public function test_install_check_reports_real_environment(): void
    {
        $code = Artisan::call('sevalia:install', ['--check' => true]);
        $output = Artisan::output();
        $this->assertStringContainsString(PHP_VERSION, $output);
        $this->assertStringContainsString('upload_max_filesize', $output);
        $this->assertStringContainsString('max_allowed_packet', $output);
        $this->assertContains($code, [0, 1]);
    }

    public function test_health_command_outputs_json(): void
    {
        Artisan::call('sevalia:health', ['--json' => true]);
        $json = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('checks', $json);
    }
}
