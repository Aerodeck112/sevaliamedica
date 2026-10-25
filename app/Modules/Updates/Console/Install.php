<?php

namespace App\Modules\Updates\Console;

use App\Core\Audit\Audit;
use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Updates\Recovery\RecoveryKey;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\EnvironmentReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Instalarea inițială pe hosting (sau doar verificarea mediului cu --check).
 *
 * Pași: verificarea mediului → directoare shared → chei (aplicație, index orb, backup, recuperare) →
 * migrări → seedere de instalare (fără date demo) → cont administrator → activarea versiunii → publicarea
 * fișierelor în public_html. Cheia de recuperare se afișează o singură dată.
 */
class Install extends Command
{
    protected $signature = 'sevalia:install
        {--check : Doar raportează mediul real (versiuni, extensii, limite, spațiu) și iese}
        {--admin-email= : Adresa primului administrator tehnic}
        {--rotate-recovery-key : Generează o cheie de recuperare nouă (cea veche nu mai funcționează)}
        {--force : Fără confirmări interactive (în afara parolei administratorului)}';

    protected $description = 'Instalează aplicația SEVALIA pe hosting sau verifică mediul';

    public function handle(EnvironmentReport $report, DeploymentLayout $layout): int
    {
        $rows = $report->collect();
        $this->table(['Grup', 'Element', 'Valoare', 'Stare', 'Observație'], array_map(fn ($r) => [
            $r['group'], $r['label'], $r['value'], match ($r['status']) { 'ok' => 'ok', 'warn' => 'atenție', default => 'EROARE' }, $r['hint'],
        ], $rows));
        if ($this->option('check')) {
            return EnvironmentReport::passed($rows) ? self::SUCCESS : self::FAILURE;
        }
        if (! EnvironmentReport::passed($rows)) {
            $this->error('Mediul are probleme marcate EROARE. Rezolvați-le înainte de instalare.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Continuați instalarea?', true)) {
            return self::FAILURE;
        }

        $layout->ensureSharedDirectories();
        $this->ensureEnvKeys();

        if (! BackupKey::exists($layout->keysPath())) {
            $key = BackupKey::generate($layout->keysPath());
            $this->warn('Cheia de backup a fost generată: '.BackupKey::path($layout->keysPath()).' (amprentă '.$key->fingerprint().').');
            $this->warn('Copiați-o OFFLINE (ex. stick criptat în seif). Fără ea backupurile nu pot fi restaurate.');
        }

        if (! RecoveryKey::exists($layout->keysPath()) || $this->option('rotate-recovery-key')) {
            // Afișată imediat: dacă un pas ulterior eșuează, cheia nu se pierde.
            $this->showRecoveryKey(RecoveryKey::generate($layout->keysPath()));
        }

        $this->info('Rulez migrările...');
        Artisan::call('migrate', ['--force' => true], $this->output);
        Artisan::call('db:seed', ['--force' => true], $this->output);

        if ($email = $this->option('admin-email')) {
            Artisan::call('sevalia:create-admin', ['email' => $email], $this->output);
        }

        if ($layout->isDeployed() && $layout->current() === null) {
            $release = basename(base_path());
            if (preg_match(DeploymentLayout::RELEASE_PATTERN, $release)) {
                $layout->activate($release, null);
                $this->info("Versiunea {$release} a fost activată (current.json).");
            } else {
                $this->warn('Directorul aplicației nu are numele unei versiuni (releases/<versiune>); current.json nu a fost scris.');
            }
        }

        if ($layout->isDeployed() && is_file(base_path('deploy/artisan'))) {
            copy(base_path('deploy/artisan'), $layout->deployPath().'/artisan');
            @chmod($layout->deployPath().'/artisan', 0755);
        }
        if ($public = $layout->publicPath()) {
            $count = $this->publishPublic(base_path(), $public);
            $this->info("Fișiere publicate în {$public}: {$count}.");
        }

        Audit::log('install.completed', context: ['versiune' => config('sevalia.version')]);
        $cron = $layout->isDeployed() ? $layout->deployPath().'/artisan' : base_path('artisan');
        $this->info("Instalare încheiată. Configurați cronul unic: * * * * * php {$cron} schedule:run >> /dev/null 2>&1");

        return self::SUCCESS;
    }

    private function showRecoveryKey(string $key): void
    {
        $this->newLine();
        $this->warn('CHEIA DE RECUPERARE (afișată o singură dată — notați-o și păstrați-o offline):');
        $this->line('    '.$key);
        $this->warn('Se folosește în public_html/recovery.php dacă aplicația nu mai pornește după o actualizare.');
        $this->newLine();
    }

    private function ensureEnvKeys(): void
    {
        $file = app()->environmentFilePath();
        if (! is_file($file)) {
            $this->warn("Fișierul de configurare {$file} nu există; copiați .env.example și completați datele bazei de date.");

            return;
        }
        $env = (string) file_get_contents($file);
        $changed = false;
        if (preg_match('/^APP_KEY=[ \t]*$/m', $env)) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $env = preg_replace('/^APP_KEY=[ \t]*$/m', 'APP_KEY='.$key, $env);
            config(['app.key' => $key]);
            app()->forgetInstance('encrypter');
            $changed = true;
        }
        if (preg_match('/^SEVALIA_BLIND_INDEX_KEY=[ \t]*$/m', $env)) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $env = preg_replace('/^SEVALIA_BLIND_INDEX_KEY=[ \t]*$/m', 'SEVALIA_BLIND_INDEX_KEY='.$key, $env);
            config(['sevalia.security.blind_index_key' => $key]);
            $changed = true;
        }
        if ($changed) {
            file_put_contents($file, $env, LOCK_EX);
            @chmod($file, 0600);
            $this->warn('Au fost generate chei noi în '.$file.'. Păstrați o copie offline a acestui fișier.');
            Artisan::call('config:clear');
        }
    }

    /** Copiază public/ (fără index.php/.htaccess) și deploy/public_html/* în public_html. */
    private function publishPublic(string $release, string $public): int
    {
        $count = 0;
        $copy = function (string $from, string $to) use (&$count) {
            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }
            if (is_file($to) && hash_file('sha256', $to) === hash_file('sha256', $from)) {
                return;
            }
            $tmp = $to.'.tmp-'.Str::random(6);
            copy($from, $tmp);
            rename($tmp, $to);
            $count++;
        };
        if (is_dir($release.'/public')) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release.'/public', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen($release.'/public') + 1);
                if ($f->isFile() && ! in_array($rel, ['index.php', '.htaccess'], true)) {
                    $copy($f->getPathname(), $public.'/'.$rel);
                }
            }
        }
        foreach (['index.php', '.htaccess', 'recovery.php'] as $f) {
            $src = $release.'/deploy/public_html/'.$f;
            if (is_file($src) && ($f !== '.htaccess' || ! is_file($public.'/.htaccess') || str_contains((string) file_get_contents($public.'/.htaccess'), 'SEVALIA-MANAGED'))) {
                $copy($src, $public.'/'.$f);
            }
        }

        return $count;
    }
}
