<?php

namespace Tests\Feature\Updates;

use App\Modules\Updates\Models\UpdateRun;
use App\Modules\Updates\Package\PackageException;
use App\Modules\Updates\Package\PackageInspector;
use App\Modules\Updates\Package\SignatureVerifier;
use App\Modules\Updates\Updater\UpdateRunner;
use Tests\Concerns\BuildsTestPackages;
use Tests\Concerns\UsesIsolatedDeployment;
use Tests\TestCase;
use ZipArchive;

class PackageVerificationTest extends TestCase
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
        $this->tearDownTestPackages();
        $this->tearDownIsolatedDeployment();
        parent::tearDown();
    }

    private function inspect(string $zip): void
    {
        app(PackageInspector::class)->inspect($zip);
    }

    /** Rescrie o arhivă: $mutate primește ZipArchive deschis pe copie. */
    private function mutate(string $zip, callable $mutate): string
    {
        $copy = $this->copyOf($zip);
        $z = new ZipArchive;
        $z->open($copy);
        $mutate($z);
        $z->close();

        return $copy;
    }

    public function test_valid_package_is_accepted_with_signature_and_manifest(): void
    {
        $package = app(PackageInspector::class)->inspect($this->buildPackage('0.2.0'));
        $this->assertSame('0.2.0', $package->manifest->version);
        $this->assertSame(16, strlen($package->keyId));
    }

    public function test_missing_manifest_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->deleteName('manifest.json'));
        $this->expectExceptionMessage('nu conține manifest.json');
        $this->inspect($zip);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->deleteName('manifest.sig'));
        $this->expectExceptionMessage('nu este semnat');
        $this->inspect($zip);
    }

    public function test_corrupt_manifest_signed_by_trusted_key_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), function ($z) {
            $z->addFromString('manifest.json', '{corupt');
            $z->addFromString('manifest.sig', SignatureVerifier::sign('{corupt', $this->secretKey));
        });
        $this->expectExceptionMessage('nu este JSON valid');
        $this->inspect($zip);
    }

    public function test_package_signed_with_untrusted_key_is_rejected(): void
    {
        $zip = $this->buildPackage('0.2.0', key: $this->untrustedSecretKey);
        $this->expectExceptionMessage('nu este de încredere');
        $this->inspect($zip);
    }

    public function test_modified_manifest_breaks_signature(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), function ($z) {
            $manifest = json_decode($z->getFromName('manifest.json'), true);
            $manifest['upgrade_from']['min'] = '0.0.1';
            $z->addFromString('manifest.json', json_encode($manifest));
        });
        $this->expectExceptionMessage('Semnătura pachetului nu este validă');
        $this->inspect($zip);
    }

    public function test_checksum_alone_is_not_enough_without_trusted_keys(): void
    {
        config(['updates.trusted_keys_paths' => []]);
        $this->expectExceptionMessage('Nu există nicio cheie publică de încredere');
        $this->inspect($this->buildPackage('0.2.0'));
    }

    public function test_file_with_same_size_but_different_content_fails_checksum_at_extraction(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->addFromString('payload/app/Exemplu.php', "<?php // VERSIUNEA 0.2.0\n"));
        $runner = app(UpdateRunner::class);
        $run = $runner->start($runner->prepare($zip));
        $run = $runner->advance($run);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Suma de control nu corespunde', $run->step('extract')['error']);
        $this->assertSame('0.1.0', json_decode(file_get_contents($this->deployRoot.'/current.json'), true)['release']);
        $this->assertNull($run->active_slot, 'Eșecul fără modificări eliberează blocarea.');
    }

    public function test_undeclared_file_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->addFromString('payload/app/Strecurat.php', '<?php'));
        $this->expectExceptionMessage('nedeclarat în manifest');
        $this->inspect($zip);
    }

    public function test_zip_slip_path_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->addFromString('payload/app/../../../../tmp/evil.php', '<?php'));
        $this->expectExceptionMessage('traversare');
        $this->inspect($zip);
    }

    public function test_absolute_path_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->addFromString('/etc/cron.d/evil', 'x'));
        $this->expectException(PackageException::class);
        $this->inspect($zip);
    }

    public function test_symlink_entry_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), function ($z) {
            $z->addFromString('payload/app/link', '/etc/passwd');
            $z->setExternalAttributesName('payload/app/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        $this->expectExceptionMessage('legătură simbolică');
        $this->inspect($zip);
    }

    public function test_zip_bomb_is_rejected(): void
    {
        $zip = $this->mutate($this->buildPackage('0.2.0'), fn ($z) => $z->addFromString('payload/app/bomba.bin', str_repeat("\0", 30 * 1024 * 1024)));
        $this->expectExceptionMessage('zip bomb');
        $this->inspect($zip);
    }

    public function test_total_uncompressed_size_limit(): void
    {
        config(['updates.limits.max_uncompressed_bytes' => 100]);
        $this->expectExceptionMessage('depășește limita');
        $this->inspect($this->buildPackage('0.2.0'));
    }

    public function test_not_a_zip_is_rejected_and_audited(): void
    {
        $file = $this->workDir.'/nu-e-zip.zip';
        file_put_contents($file, 'text simplu');
        try {
            app(UpdateRunner::class)->prepare($file);
            $this->fail('Trebuia respins.');
        } catch (PackageException $e) {
            $this->assertStringContainsString('nu este o arhivă ZIP validă', $e->getMessage());
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'updates.package_rejected']);
        $this->assertSame(0, UpdateRun::count());
    }
}
