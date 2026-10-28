<?php

namespace App\Modules\Updates\Support;

use App\Modules\Backup\Crypto\BackupKey;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Verificările de funcționare ale codului care rulează în procesul curent: bază de date, migrări, stocare, chei.
 * Folosite de comanda sevalia:health și de updater, după activare, în prima cerere servită de noul cod.
 */
class AppHealth
{
    public function __construct(private readonly DeploymentLayout $layout) {}

    /** @return list<array{check: string, ok: bool, detail: string, critical: bool}> */
    public function checks(): array
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
        $add('cheia de backup', BackupKey::exists($this->layout->keysPath()), $this->layout->keysPath(), false);

        return $checks;
    }

    /** @param list<array{ok: bool, critical: bool}> $checks */
    public static function passed(array $checks): bool
    {
        foreach ($checks as $c) {
            if (! $c['ok'] && $c['critical']) {
                return false;
            }
        }

        return true;
    }
}
