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
        {--cpanel : Pentru install: produce și pachetul „instalare cPanel” (de dezarhivat în File Manager, cu install.php)}
        {--vendor=local : local (copiază vendor/ și elimină offline pachetele dev cu composer install --no-dev) | composer (instalare curată, necesită rețea) | copy (doar pentru teste)}';

    protected $description = 'Construiește un pachet ZIP semnat (install sau update)';

    /** Directoare și fișiere incluse în payload. */
    private const INCLUDE = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'deploy',
        'artisan', 'composer.json', 'composer.lock', 'VERSION', 'CHANGELOG.md', '.env.example'];

    /** Excluderi (prefixe relative). Datele demo și fișierele locale nu ajung în pachete. */
    private const EXCLUDE = ['bootstrap/cache/', 'public/hot', 'public/storage', 'database/seeders/Demo/', 'database/database.sqlite',
        'public/build/.vite/', 'public/media/'];

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

        $tmp = null;
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

            $outDir = (string) $this->option('out');
            $outDir = str_starts_with($outDir, '/') ? $outDir : base_path($outDir);
            $out = rtrim($outDir, '/')."/sevalia-{$version}-{$type}.zip";
            $result = $builder->build($tmp, $out, $secret, [
                'type' => $type, 'version' => $version, 'upgrade_from' => $from,
                'changelog' => $this->changelogFor($version),
                'builder' => ['git_commit' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null')), 'host' => gethostname()],
            ]);
            sodium_memzero($secret);
            if ($type === 'install' && $this->option('cpanel')) {
                $bundle = $this->cpanelBundle($tmp, $version, rtrim($outDir, '/'));
                $this->info("Pachet de instalare cPanel (fără Terminal): {$bundle}");
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // Directorul temporar se șterge întotdeauna, inclusiv la eșec (spațiu pe disc).
            if ($tmp !== null) {
                $this->removeDirectory($tmp);
            }
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
            (new Process(['cp', '-a', base_path('vendor'), $tmp.'/vendor'], null, null, null, 900))->mustRun();

            return;
        }
        if ($this->option('vendor') === 'local') {
            // Pornim de la vendor/ existent: composer doar elimină pachetele dev (fără descărcări) și
            // regenerează autoloaderul optimizat pentru producție. vendor/ poate avea peste 1 GB cu pachetele dev.
            (new Process(['cp', '-a', base_path('vendor'), $tmp.'/vendor'], null, null, null, 900))->mustRun();
        }
        $process = new Process(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--prefer-dist', '--no-progress'], $tmp, ['COMPOSER_ALLOW_SUPERUSER' => '1'], null, 900);
        $process->run(fn ($t, $line) => $this->output->write($line));
        if (! $process->isSuccessful()) {
            throw new RuntimeException('composer install --no-dev a eșuat.');
        }
        $this->pruneVendor($tmp.'/vendor');
    }

    /**
     * Elimină din vendor/ ce nu e necesar la rulare și ce pachetul nu are voie să conțină: depozite .git
     * (la instalările din sursă), teste/documentație de la rădăcina pachetelor și legături simbolice.
     */
    private function pruneVendor(string $vendor): void
    {
        foreach (glob($vendor.'/*/*', GLOB_ONLYDIR) ?: [] as $package) {
            foreach (['.git', '.github', 'tests', 'Tests', 'test', 'docs', 'doc'] as $dir) {
                if (is_dir($package.'/'.$dir) && ! is_link($package.'/'.$dir)) {
                    $this->removeDirectory($package.'/'.$dir);
                }
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($vendor, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isLink()) {
                @unlink($f->getPathname());
            }
        }
    }

    /**
     * Arhiva pentru instalarea fără Terminal. Se dezarhivează în directorul principal al contului cPanel:
     *   sevalia/releases/<versiune>/…   codul aplicației (în afara public_html)
     *   public_html/install.php         instalatorul din browser
     *   CITESTE-MA-INSTALARE.txt        pașii pe scurt
     */
    private function cpanelBundle(string $tmp, string $version, string $outDir): string
    {
        $file = "{$outDir}/sevalia-{$version}-instalare-cpanel.zip";
        @unlink($file);
        $zip = new \ZipArchive;
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Nu pot crea {$file}.");
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || $f->isLink()) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($tmp) + 1);
            $zip->addFile($f->getPathname(), "sevalia/releases/{$version}/{$rel}");
        }
        $zip->addFile($tmp.'/deploy/public_html/install.php', 'public_html/install.php');
        $zip->addFromString('CITESTE-MA-INSTALARE.txt', str_replace('{versiune}', $version, (string) file_get_contents(base_path('deploy/CITESTE-MA-INSTALARE.txt'))));
        if (! $zip->close()) {
            throw new RuntimeException("Arhiva {$file} nu a putut fi scrisă.");
        }
        file_put_contents($file.'.sha256', hash_file('sha256', $file).'  '.basename($file)."\n");

        return $file;
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
