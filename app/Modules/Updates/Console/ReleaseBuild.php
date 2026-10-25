<?php

namespace App\Modules\Updates\Console;

use App\Modules\Updates\Release\PackageBuilder;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Construiește pachetele de instalare și actualizare din codul curent (pe mașina de build / CI).
 */
class ReleaseBuild extends Command
{
    protected $signature = 'sevalia:release:build
        {--type=update : install | update}
        {--from= : Pentru update: intervalul de versiuni acceptat, „min-max” (ex. 0.1.0-0.1.99)}
        {--key= : Calea cheii private Ed25519}
        {--out=dist : Directorul de ieșire}
        {--vendor=composer : composer (composer install --no-dev într-un director temporar) | copy (copiază vendor/ existent; doar pentru teste)}';

    protected $description = 'Construiește un pachet ZIP semnat (install sau update)';

    /** Directoare și fișiere incluse în payload. */
    private const INCLUDE = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'deploy',
        'artisan', 'composer.json', 'composer.lock', 'VERSION', 'CHANGELOG.md'];

    /** Excluderi (prefixe relative). Datele demo și fișierele locale nu ajung în pachete. */
    private const EXCLUDE = ['bootstrap/cache/', 'public/hot', 'public/storage', 'database/seeders/Demo/', 'database/database.sqlite',
        'public/build/.vite/'];

    public function handle(PackageBuilder $builder): int
    {
        $type = $this->option('type');
        if (! in_array($type, ['install', 'update'], true)) {
            $this->error('--type trebuie să fie install sau update.');

            return self::FAILURE;
        }
        $version = trim((string) file_get_contents(base_path('VERSION')));
        $from = null;
        if ($type === 'update') {
            if (! preg_match('/^([\w.\-]+?)-(\d[\w.]*)$/', (string) $this->option('from'), $m)) {
                $this->error('Pentru update indicați --from=<min>-<max>, ex. 0.1.0-0.1.99.');

                return self::FAILURE;
            }
            $from = ['min' => $m[1], 'max' => $m[2]];
        }
        if (! is_file(base_path('public/build/manifest.json'))) {
            $this->error('Lipsesc fișierele compilate (public/build). Rulați „npm ci && npm run build” înainte.');

            return self::FAILURE;
        }

        try {
            $secret = PackageBuilder::loadSecretKey((string) $this->option('key'));
            if (str_starts_with((string) realpath((string) $this->option('key')), realpath(base_path()).'/')) {
                $this->warn('Atenție: cheia privată se află în directorul proiectului. Mutați-o în afara repository-ului.');
            }
            $tmp = sys_get_temp_dir().'/sevalia-build-'.bin2hex(random_bytes(4));
            mkdir($tmp, 0755, true);
            $this->info("Copiez sursele în {$tmp}...");
            $this->copySources($tmp, $type);
            $this->info('Pregătesc dependențele de producție...');
            $this->vendor($tmp);

            $out = rtrim(base_path($this->option('out')), '/')."/sevalia-{$version}-{$type}.zip";
            $result = $builder->build($tmp, $out, $secret, [
                'type' => $type, 'version' => $version, 'upgrade_from' => $from,
                'changelog' => $this->changelogFor($version),
                'builder' => ['git_commit' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null')), 'host' => gethostname()],
            ]);
            sodium_memzero($secret);
            $this->removeDirectory($tmp);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pachet: {$result['file']}");
        $this->line("  fișiere: {$result['files']}, dimensiune necomprimată: ".number_format($result['bytes'] / 1048576, 1, ',', '.').' MB');
        $this->line("  SHA-256: {$result['sha256']}");

        return self::SUCCESS;
    }

    private function copySources(string $tmp, string $type): void
    {
        $base = realpath(base_path());
        foreach (self::INCLUDE as $item) {
            $src = $base.'/'.$item;
            if (is_file($src)) {
                copy($src, $tmp.'/'.$item);
            } elseif (is_dir($src)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $rel = substr($f->getPathname(), strlen($base) + 1);
                    if (! $f->isFile() || $f->isLink() || $this->excluded($rel, $type)) {
                        continue;
                    }
                    @mkdir(dirname($tmp.'/'.$rel), 0755, true);
                    copy($f->getPathname(), $tmp.'/'.$rel);
                }
            }
        }
        @mkdir($tmp.'/bootstrap/cache', 0755, true);
        file_put_contents($tmp.'/bootstrap/cache/.gitignore', "*\n!.gitignore\n");
    }

    private function excluded(string $rel, string $type): bool
    {
        foreach (self::EXCLUDE as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }
        // Instantaneul siteului vechi e necesar doar la instalarea inițială (importul conținutului).
        if ($type === 'update' && str_starts_with($rel, 'database/content/')) {
            return true;
        }

        return str_ends_with($rel, '.DS_Store');
    }

    private function vendor(string $tmp): void
    {
        if ($this->option('vendor') === 'copy') {
            $this->warn('vendor/ copiat din proiect (poate conține pachete de dezvoltare) — doar pentru teste.');
            (new Process(['cp', '-a', base_path('vendor'), $tmp.'/vendor']))->mustRun();

            return;
        }
        $process = new Process(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--prefer-dist'], $tmp, ['COMPOSER_ALLOW_SUPERUSER' => '1'], null, 900);
        $process->run(fn ($t, $line) => $this->output->write($line));
        if (! $process->isSuccessful()) {
            throw new RuntimeException('composer install --no-dev a eșuat.');
        }
    }

    private function changelogFor(string $version): string
    {
        $text = (string) @file_get_contents(base_path('CHANGELOG.md'));
        if (preg_match('/^## \['.preg_quote($version, '/').'\].*?(?=^## \[|\z)/ms', $text, $m)) {
            return trim($m[0]);
        }

        return '';
    }

    private function removeDirectory(string $dir): void
    {
        (new Process(['rm', '-rf', $dir]))->run();
    }
}
