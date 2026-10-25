<?php

namespace App\Modules\Updates\Console;

use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Updates\Support\DeploymentLayout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Verificare de funcționare a versiunii care rulează comanda. Folosită de updater după activare (într-un
 * proces separat, cu noul cod) și utilă pentru monitorizare din cron.
 */
class Health extends Command
{
    protected $signature = 'sevalia:health {--json : Rezultat JSON pe o singură linie}';

    protected $description = 'Verifică pornirea aplicației: bază de date, migrări, stocare, chei';

    public function handle(DeploymentLayout $layout): int
    {
        $checks = [];
        $add = function (string $name, bool $ok, string $detail = '', bool $critical = true) use (&$checks) {
            $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical];
        };

        try {
            DB::connection()->select('SELECT 1');
            $add('baza de date', true, DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            $add('baza de date', false, $e->getMessage());
        }

        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $pending = array_diff(array_keys($files), $ran);
            $add('migrări', $pending === [], $pending ? 'nerulate: '.implode(', ', array_slice($pending, 0, 5)) : 'toate rulate');
        } catch (Throwable $e) {
            $add('migrări', false, $e->getMessage());
        }

        $storage = storage_path('framework');
        $add('stocare', is_dir($storage) && is_writable($storage), $storage);
        $add('cheia aplicației', (string) config('app.key') !== '', '');
        $add('cheia de backup', BackupKey::exists($layout->keysPath()), $layout->keysPath(), false);

        $ok = ! collect($checks)->contains(fn ($c) => ! $c['ok'] && $c['critical']);
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'version' => config('sevalia.version'), 'checks' => $checks], JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $c) {
                $this->line(($c['ok'] ? '[ok]   ' : ($c['critical'] ? '[EROARE] ' : '[atenție] ')).$c['check'].($c['detail'] ? ' — '.$c['detail'] : ''));
            }
            $ok ? $this->info('Aplicația funcționează (versiunea '.config('sevalia.version').').') : $this->error('Există probleme critice.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
