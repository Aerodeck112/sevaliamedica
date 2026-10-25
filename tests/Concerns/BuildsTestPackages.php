<?php

namespace Tests\Concerns;

use App\Modules\Updates\Package\SignatureVerifier;
use App\Modules\Updates\Release\PackageBuilder;
use App\Modules\Updates\Support\HealthChecker;

/**
 * Pachete de test semnate cu chei efemere (generate la fiecare test, niciodată salvate în repository).
 */
trait BuildsTestPackages
{
    protected string $secretKey;

    protected string $untrustedSecretKey;

    protected string $workDir;

    protected function setUpTestPackages(): void
    {
        $this->workDir = sys_get_temp_dir().'/sevalia-pkg-'.bin2hex(random_bytes(6));
        mkdir($this->workDir.'/keys', 0700, true);
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        file_put_contents($this->workDir.'/keys/'.SignatureVerifier::keyId($public).'.pub', base64_encode($public));
        $this->untrustedSecretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        config(['updates.trusted_keys_paths' => [$this->workDir.'/keys']]);
        $this->fakeHealth(true);
    }

    protected function tearDownTestPackages(): void
    {
        if (isset($this->workDir)) {
            $this->removeDirectory($this->workDir);
        }
    }

    /** Conținutul minim al unei versiuni, plus fișiere/migrări suplimentare. */
    protected function payloadDir(string $version, array $files = []): string
    {
        $dir = $this->workDir.'/src-'.$version.'-'.bin2hex(random_bytes(3));
        $base = [
            'VERSION' => $version."\n",
            'artisan' => "#!/usr/bin/env php\n<?php // fictiv\n",
            'vendor/autoload.php' => "<?php\n",
            'bootstrap/app.php' => "<?php // fictiv\n",
            "public/build/assets/panou-{$version}.css" => "body{color:#0e3b3d}\n",
            'public/index.php' => "<?php // nu se publică\n",
            'deploy/public_html/recovery.php' => "<?php // recuperare {$version}\n",
            'app/Exemplu.php' => "<?php // versiunea {$version}\n",
        ];
        foreach ($base + $files as $path => $content) {
            @mkdir(dirname($dir.'/'.$path), 0755, true);
            file_put_contents($dir.'/'.$path, $content);
        }

        return $dir;
    }

    protected function buildPackage(string $version, array $files = [], array $meta = [], ?string $key = null): string
    {
        $out = $this->workDir.'/sevalia-'.$version.'-'.bin2hex(random_bytes(3)).'.zip';
        app(PackageBuilder::class)->build($this->payloadDir($version, $files), $out, $key ?? $this->secretKey, $meta + [
            'type' => 'update', 'version' => $version, 'upgrade_from' => ['min' => '0.1.0', 'max' => '0.9.99'],
            'changelog' => "## [{$version}]\n- Modificare fictivă de test.",
        ]);

        return $out;
    }

    /** O copie a pachetului (prepare() mută fișierul primit). */
    protected function copyOf(string $zip): string
    {
        $copy = $zip.'.'.bin2hex(random_bytes(3)).'.zip';
        copy($zip, $copy);

        return $copy;
    }

    protected function fakeHealth(bool $ok): void
    {
        $this->app->instance(HealthChecker::class, new class($ok) implements HealthChecker
        {
            public function __construct(private bool $ok) {}

            public function check(string $releasePath, string $expectedVersion): array
            {
                return [['check' => 'verificare simulată', 'ok' => $this->ok, 'detail' => $this->ok ? '' : 'eșec simulat']];
            }
        });
    }

    protected static function migration(string $up): string
    {
        return "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n"
            ."return new class extends Migration {\n    public function up(): void\n    {\n        {$up}\n    }\n};\n";
    }
}
