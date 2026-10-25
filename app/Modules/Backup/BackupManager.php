<?php

namespace App\Modules\Backup;

use App\Core\Audit\Audit;
use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Backup\Dump\BackupReader;
use App\Modules\Backup\Dump\DatabaseDumper;
use App\Modules\Backup\Dump\DocumentsArchiver;
use App\Modules\Backup\Models\Backup;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\DiskSpace;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creează, continuă, verifică și curăță backupurile. Fiecare backup rulează pe bucăți cu stare salvată în
 * shared/backups/.state/<uuid>.json, ca să poată fi dus la capăt din cron (CLI, fără limită de timp) sau din
 * pași succesivi ai unei actualizări (cereri web scurte).
 */
class BackupManager
{
    public function __construct(
        private readonly DeploymentLayout $layout,
        private readonly DiskSpace $disk,
    ) {}

    public function key(): BackupKey
    {
        return BackupKey::load($this->layout->keysPath());
    }

    public function ensureKey(): BackupKey
    {
        return BackupKey::exists($this->layout->keysPath())
            ? $this->key()
            : BackupKey::generate($this->layout->keysPath());
    }

    public function start(string $type, string $trigger = 'manual', ?int $userId = null): Backup
    {
        if (! isset(Backup::TYPES[$type])) {
            throw new RuntimeException('Tip de backup necunoscut.');
        }
        $key = $this->key();
        $this->assertSpace($type);

        $uuid = (string) Str::uuid();
        $rel = gmdate('Y/m/').$type.'-'.gmdate('Ymd-His').'-'.substr($uuid, 0, 8).'.sbk';
        $file = $this->layout->backupsPath($rel);

        $backup = Backup::create([
            'uuid' => $uuid, 'type' => $type, 'status' => 'running', 'trigger' => $trigger, 'file' => $rel,
            'key_fingerprint' => $key->fingerprint(), 'app_version' => config('sevalia.version'),
            'created_by' => $userId, 'started_at' => now(),
        ]);

        try {
            $meta = ['app_version' => config('sevalia.version'), 'backup_uuid' => $uuid];
            $state = $type === 'database'
                ? $this->dumper()->start($file, $key, $meta)
                : (new DocumentsArchiver)->start($file, $key, $this->documentRoots(), $meta);
            $this->saveState($backup, $state);
        } catch (Throwable $e) {
            $this->fail($backup, $e);
            throw $e;
        }

        return $backup;
    }

    /** Avansează backupul până la $deadline. Întoarce true când e finalizat. */
    public function step(Backup $backup, float $deadline): bool
    {
        if ($backup->status !== 'running') {
            return $backup->isUsable();
        }
        $file = $this->layout->backupsPath($backup->file);
        try {
            $state = $this->loadState($backup);
            $state = $backup->type === 'database'
                ? $this->dumper()->step($file, $state, $deadline)
                : (new DocumentsArchiver)->step($file, $state, $deadline);
            $this->saveState($backup, $state);
            if (! $state['done']) {
                return false;
            }
            $this->finalize($backup, $state);

            return true;
        } catch (Throwable $e) {
            $this->fail($backup, $e);
            throw $e;
        }
    }

    public function runToCompletion(Backup $backup): Backup
    {
        while (! $this->step($backup, microtime(true) + 30)) {
            // continuă până la final
        }

        return $backup->refresh();
    }

    /** Verificare reală: suma SHA-256 a fișierului + decriptare și autentificare completă + numărare. */
    public function verify(Backup $backup): array
    {
        $file = $this->layout->backupsPath($backup->file);
        if (! is_file($file)) {
            throw new RuntimeException('Fișierul de backup lipsește.');
        }
        if ($backup->sha256 && ! hash_equals($backup->sha256, hash_file('sha256', $file))) {
            throw new RuntimeException('Suma de control a fișierului de backup nu corespunde.');
        }
        $result = $backup->type === 'database'
            ? (new BackupReader)->verifyDatabase($file, $this->key())
            : (new DocumentsArchiver)->verify($file, $this->key());

        $backup->forceFill(['status' => 'verified', 'verified_at' => now()])->save();
        Audit::log('backup.verified', $backup);

        return $result;
    }

    /**
     * Restaurează un backup de bază de date în conexiunea țintă, până la $deadline. Întoarce starea.
     * Apelantul răspunde de confirmarea explicită și de alegerea bazei țintă.
     */
    public function restoreStep(Backup $backup, Connection $target, ?array $state, float $deadline): array
    {
        if ($backup->type !== 'database' || ! $backup->isUsable()) {
            throw new RuntimeException('Backupul nu poate fi restaurat.');
        }
        $file = $this->layout->backupsPath($backup->file);
        $reader = new BackupReader;
        if ($state === null) {
            if ($backup->sha256 && ! hash_equals($backup->sha256, hash_file('sha256', $file))) {
                throw new RuntimeException('Suma de control a fișierului de backup nu corespunde; restaurarea este oprită.');
            }
            $state = $reader->startRestore($file, $this->key());
        }

        return $reader->stepRestore($file, $state, $target, $deadline);
    }

    /** Șterge backupurile vechi peste numărul păstrat; nu atinge backupurile legate de actualizări neîncheiate. */
    public function prune(string $type): int
    {
        $keep = (int) (config('updates.backup.retain')[$type] ?? 14);
        $protected = DB::table('update_runs')->whereIn('status', ['prepared', 'running', 'failed'])->whereNotNull('backup_id')->pluck('backup_id')->all();
        $old = Backup::where('type', $type)->whereIn('status', ['completed', 'verified'])
            ->orderByDesc('id')->get()->slice($keep)
            ->reject(fn (Backup $b) => in_array($b->id, $protected, true));
        foreach ($old as $backup) {
            @unlink($this->layout->backupsPath($backup->file));
            @unlink($this->layout->backupsPath($backup->file).'.json');
            Audit::log('backup.pruned', $backup);
            $backup->delete();
        }
        // Backupurile eșuate mai vechi de o zi: se elimină fișierul parțial.
        foreach (Backup::where('status', 'failed')->where('created_at', '<', now()->subDay())->get() as $failed) {
            @unlink($this->layout->backupsPath($failed->file));
        }

        return $old->count();
    }

    public function documentRoots(): array
    {
        $roots = [];
        foreach ((array) config('updates.backup.documents_dirs', []) as $dir) {
            $roots[str_replace('/', '_', trim($dir, '/'))] = storage_path(trim($dir, '/'));
        }

        return $roots;
    }

    private function finalize(Backup $backup, array $state): void
    {
        $file = $this->layout->backupsPath($backup->file);
        $summary = $backup->type === 'database'
            ? ['statements' => $state['statements'], 'tables' => count($state['tables']), 'rows' => array_sum($state['rows'])]
            : ['files' => count($state['files']), 'bytes' => $state['bytes']];
        $backup->forceFill([
            'status' => 'completed', 'size' => filesize($file), 'sha256' => hash_file('sha256', $file),
            'summary' => $summary, 'finished_at' => now(),
        ])->save();

        // Manifest necriptat, fără date personale: permite verificarea integrității fără cheie.
        file_put_contents($file.'.json', json_encode([
            'uuid' => $backup->uuid, 'type' => $backup->type, 'sha256' => $backup->sha256, 'size' => $backup->size,
            'key_fingerprint' => $backup->key_fingerprint, 'app_version' => $backup->app_version,
            'created_at' => $backup->started_at?->toIso8601String(), 'finished_at' => $backup->finished_at?->toIso8601String(),
            'summary' => $summary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @unlink($this->statePath($backup));
        Audit::log('backup.completed', $backup, ['tip' => $backup->type, 'declansator' => $backup->trigger]);
    }

    private function fail(Backup $backup, Throwable $e): void
    {
        $backup->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
        Audit::log('backup.failed', $backup, ['eroare' => mb_substr($e->getMessage(), 0, 300)]);
    }

    private function assertSpace(string $type): void
    {
        $free = $this->disk->freeBytes($this->layout->backupsPath());
        if ($free === null) {
            return;
        }
        $needed = $type === 'database' ? $this->databaseSize() : $this->documentsSize();
        // Dumpul comprimat e de obicei mult mai mic; cerem totuși marja dimensiunii brute + 20%.
        if ($free < (int) ($needed * 1.2) + 10 * 1024 * 1024) {
            throw new RuntimeException('Spațiu insuficient pentru backup: liber '.self::human($free).', necesar estimat '.self::human((int) ($needed * 1.2)).'.');
        }
    }

    private function databaseSize(): int
    {
        $db = $this->connection();

        return (int) $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())
            ->sum($db->raw('data_length + index_length'));
    }

    private function documentsSize(): int
    {
        $total = 0;
        foreach ($this->documentRoots() as $dir) {
            if (is_dir($dir)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $total += $f->isFile() ? $f->getSize() : 0;
                }
            }
        }

        return $total;
    }

    private function dumper(): DatabaseDumper
    {
        return new DatabaseDumper(
            $this->connection(),
            config('updates.backup.structure_only', []),
            (int) config('updates.backup.rows_per_insert', 200),
            (int) config('updates.backup.rows_per_chunk', 2000),
        );
    }

    /** Conexiunea bazei de date a clinicii (implicit conexiunea aplicației). */
    public function connection(): Connection
    {
        return DB::connection(config('updates.connection'));
    }

    private function statePath(Backup $backup): string
    {
        return $this->layout->backupsPath('.state/'.$backup->uuid.'.json');
    }

    private function saveState(Backup $backup, array $state): void
    {
        $path = $this->statePath($backup);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $tmp = $path.'.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $path);
    }

    private function loadState(Backup $backup): array
    {
        $state = json_decode((string) @file_get_contents($this->statePath($backup)), true);
        if (! is_array($state)) {
            throw new RuntimeException('Starea backupului în curs lipsește; backupul trebuie reluat de la început.');
        }

        return $state;
    }

    public static function human(int $bytes): string
    {
        $units = ['o', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return number_format($v, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    }
}
