<?php

namespace App\Modules\Updates\Updater;

use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Backup\Models\Backup;
use App\Modules\Updates\Package\Manifest;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\DiskSpace;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Verificările preliminare ale unei instalări. Fiecare rezultat are stare ok / warn / fail;
 * un singur „fail” oprește instalarea înainte de orice modificare.
 */
class Preflight
{
    public function __construct(
        private readonly DeploymentLayout $layout,
        private readonly DiskSpace $disk,
    ) {}

    /** @return list<array{key: string, label: string, status: string, detail: string}> */
    public function run(Manifest $manifest, ?string $currentVersion, ?int $ignoreRunId = null): array
    {
        $r = [];
        $add = function (string $key, string $label, string $status, string $detail = '') use (&$r) {
            $r[] = compact('key', 'label', 'status', 'detail');
        };

        $add('layout', 'Structura de deployment (releases/, shared/, current.json)',
            $this->layout->isDeployed() && $this->layout->current() ? 'ok' : 'fail',
            $this->layout->isDeployed() ? 'versiune activă: '.($this->layout->current()['release'] ?? '—')
                : 'Aplicația nu rulează din structura de releases (mod dezvoltare). Pachetul poate fi verificat, dar nu instalat.');

        $incompatible = $manifest->incompatibilityWith($currentVersion);
        $add('version', 'Compatibilitatea versiunii', $incompatible ? 'fail' : 'ok',
            $incompatible ?? "{$currentVersion} → {$manifest->version}");

        $phpOk = version_compare(PHP_VERSION, $manifest->phpMin, '>=');
        $add('php', 'Versiunea PHP', $phpOk ? 'ok' : 'fail', 'disponibil '.PHP_VERSION.', necesar ≥ '.$manifest->phpMin);

        $needed = array_unique([...config('updates.required_extensions', []), ...$manifest->extensions]);
        $missing = array_values(array_filter($needed, fn ($e) => ! extension_loaded($e)));
        $add('extensions', 'Extensii PHP', $missing ? 'fail' : 'ok', $missing ? 'lipsesc: '.implode(', ', $missing) : implode(', ', $needed));

        $add('database', 'Versiunea bazei de date', ...$this->databaseCheck($manifest->databases));

        $free = $this->disk->freeBytes($this->layout->sharedPath());
        $neededBytes = $manifest->totalBytes() * (int) config('updates.limits.free_space_factor', 3) + $this->databaseSize();
        if ($free === null) {
            $add('disk', 'Spațiu liber pe disc', 'warn', 'nu poate fi determinat pe acest hosting; necesar estimat '.self::human($neededBytes));
        } else {
            $add('disk', 'Spațiu liber pe disc', $free >= $neededBytes ? 'ok' : 'fail',
                'liber '.self::human($free).', necesar estimat '.self::human($neededBytes));
        }

        $paths = $this->layout->isDeployed()
            ? [$this->layout->deployPath(), $this->layout->releasesPath(), $this->layout->updatesPath(), $this->layout->backupsPath(), $this->layout->sharedPath('storage')]
            : [$this->layout->updatesPath()];
        if ($this->layout->publicPath()) {
            $paths[] = $this->layout->publicPath();
        }
        $notWritable = array_values(array_filter($paths, fn ($p) => ! is_dir($p) || ! is_writable($p)));
        $add('writable', 'Permisiuni de scriere', $notWritable ? 'fail' : 'ok', $notWritable ? 'fără drept de scriere: '.implode(', ', $notWritable) : 'directoarele necesare pot fi scrise');

        $add('backup_key', 'Cheia de backup', BackupKey::exists($this->layout->keysPath()) ? 'ok' : 'fail',
            BackupKey::exists($this->layout->keysPath()) ? 'prezentă (păstrați o copie offline)' : 'lipsește: backupul obligatoriu dinaintea instalării nu poate fi creat');

        $other = \App\Modules\Updates\Models\UpdateRun::whereNotNull('active_slot')->when($ignoreRunId, fn ($q) => $q->where('id', '!=', $ignoreRunId))->first();
        $add('concurrency', 'Nicio altă actualizare în curs', $other ? 'fail' : 'ok', $other ? 'actualizarea '.$other->to_version.' este încă activă' : '');

        $running = Backup::where('status', 'running')->where('started_at', '>', now()->subHours(6))->exists();
        $add('backup_running', 'Niciun backup în curs', $running ? 'warn' : 'ok', $running ? 'un backup programat rulează acum; se recomandă așteptarea' : '');

        if (app()->isDownForMaintenance()) {
            $add('maintenance', 'Modul mentenanță', 'warn', 'aplicația este deja în mentenanță');
        }

        return $r;
    }

    public static function passed(array $results): bool
    {
        return ! in_array('fail', array_column($results, 'status'), true);
    }

    /** @return array{0: string, 1: string} */
    private function databaseCheck(array $requirements): array
    {
        try {
            $version = (string) DB::connection(config('updates.connection'))->selectOne('SELECT VERSION() AS v')->v;
        } catch (Throwable $e) {
            return ['fail', 'conexiune eșuată: '.$e->getMessage()];
        }
        $engine = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        $numeric = preg_replace('/^(\d+\.\d+(\.\d+)?).*/', '$1', $version);
        $min = $requirements[$engine] ?? null;
        if ($min === null) {
            return ['fail', "motorul {$engine} {$numeric} nu este declarat compatibil de pachet"];
        }

        return version_compare($numeric, $min, '>=')
            ? ['ok', "{$engine} {$numeric} (necesar ≥ {$min})"]
            : ['fail', "{$engine} {$numeric}, necesar ≥ {$min}"];
    }

    private function databaseSize(): int
    {
        try {
            $db = DB::connection(config('updates.connection'));

            return (int) $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())->sum($db->raw('data_length + index_length'));
        } catch (Throwable) {
            return 0;
        }
    }

    public static function human(int $bytes): string
    {
        return \App\Modules\Backup\BackupManager::human($bytes);
    }
}
