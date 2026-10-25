<?php

namespace Tests\Feature\Backup;

use App\Modules\Backup\BackupManager;
use App\Modules\Backup\Dump\DocumentsArchiver;
use App\Modules\Backup\Models\Backup;
use App\Modules\Updates\Support\DiskSpace;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesIsolatedDeployment;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use UsesIsolatedDeployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedDeployment(withRelease: false);
        $this->seedClinicData();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDeployment();
        parent::tearDown();
    }

    private function seedClinicData(): void
    {
        $db = DB::connection('clinic_data');
        $db->statement('CREATE TABLE pacienti_demo (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nume VARCHAR(100), note TEXT NULL, poza VARBINARY(64) NULL, creat_la TIMESTAMP NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->statement('CREATE TABLE fara_cheie (cod VARCHAR(10), valoare DECIMAL(10,2)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->statement('CREATE TABLE sessions (id VARCHAR(40) PRIMARY KEY, payload TEXT) ENGINE=InnoDB');
        $db->table('sessions')->insert(['id' => 'x', 'payload' => 'tranzitoriu']);
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = [
                'nume' => "Pacient Fictiv {$i} — Șțăîâ",
                'note' => $i % 3 ? "rând 1\nrând 2; cu ' ghilimele \" și \\ backslash\r\n" : null,
                'poza' => $i % 5 ? random_bytes(16) : null,
                'creat_la' => '2026-10-25 10:00:00',
            ];
            if (count($rows) === 500) {
                $db->table('pacienti_demo')->insert($rows);
                $rows = [];
            }
        }
        $db->table('fara_cheie')->insert([['cod' => 'A', 'valoare' => 1.5], ['cod' => 'B', 'valoare' => null]]);
    }

    private function snapshot(string $connection): array
    {
        $db = DB::connection($connection);

        return [
            'pacienti' => $db->table('pacienti_demo')->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->nume, $r->note, bin2hex((string) $r->poza), $r->creat_la])->all(),
            'fara_cheie' => $db->table('fara_cheie')->orderBy('cod')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_backup_verify_and_restore_produce_identical_data(): void
    {
        $manager = app(BackupManager::class);
        $backup = $manager->runToCompletion($manager->start('database', 'manual'));

        $this->assertSame('completed', $backup->status);
        $this->assertFileExists(config('updates.shared_path').'/backups/'.$backup->file.'.json');
        $raw = file_get_contents(config('updates.shared_path').'/backups/'.$backup->file);
        $this->assertStringNotContainsString('Pacient Fictiv', $raw, 'Backupul trebuie să fie criptat.');

        $result = $manager->verify($backup->refresh());
        $this->assertSame('verified', $backup->refresh()->status);
        $this->assertSame(2502, $result['footer']['rows']['pacienti_demo'] + $result['footer']['rows']['fara_cheie']);

        $state = null;
        do {
            $state = $manager->restoreStep($backup, DB::connection('restore_target'), $state, microtime(true) + 10);
        } while (! $state['done']);

        $this->assertSame($this->snapshot('clinic_data'), $this->snapshot('restore_target'));
        // Tabelele tranzitorii se salvează doar ca structură.
        $this->assertSame(0, DB::connection('restore_target')->table('sessions')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.completed']);
    }

    public function test_interrupted_backup_resumes_with_tiny_steps(): void
    {
        config(['updates.backup.rows_per_chunk' => 300]);
        $manager = app(BackupManager::class);
        $backup = $manager->start('database', 'manual');
        $steps = 0;
        // Termen deja expirat: fiecare apel face exact o unitate de lucru, apoi se oprește (simulează timeout).
        while (! $manager->step($backup, microtime(true) - 1)) {
            $steps++;
            $this->assertLessThan(100, $steps);
        }
        $this->assertGreaterThan(5, $steps);
        $manager->verify($backup->refresh());

        $state = null;
        do {
            $state = $manager->restoreStep($backup, DB::connection('restore_target'), $state, microtime(true) - 1);
        } while (! $state['done']);
        $this->assertSame($this->snapshot('clinic_data'), $this->snapshot('restore_target'));
    }

    public function test_tampered_or_truncated_backup_is_rejected(): void
    {
        $manager = app(BackupManager::class);
        $backup = $manager->runToCompletion($manager->start('database'));
        $file = config('updates.shared_path').'/backups/'.$backup->file;

        $bytes = file_get_contents($file);
        $bytes[intdiv(strlen($bytes), 2)] = chr(ord($bytes[intdiv(strlen($bytes), 2)]) ^ 0xFF);
        file_put_contents($file, $bytes);
        try {
            $manager->verify($backup);
            $this->fail('Backupul modificat trebuia respins.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Suma de control', $e->getMessage());
        }

        // Fără suma din registru, autentificarea blocurilor detectează modificarea.
        $backup->forceFill(['sha256' => null])->save();
        $this->expectException(RuntimeException::class);
        $manager->verify($backup);
    }

    public function test_truncated_backup_is_detected_by_stream_authentication(): void
    {
        $manager = app(BackupManager::class);
        $backup = $manager->runToCompletion($manager->start('database'));
        $file = config('updates.shared_path').'/backups/'.$backup->file;
        $handle = fopen($file, 'r+');
        ftruncate($handle, filesize($file) - 40);
        fclose($handle);
        $backup->forceFill(['sha256' => null])->save();

        $this->expectExceptionMessageMatches('/trunchiat|corupt/');
        $manager->verify($backup);
    }

    public function test_backup_refuses_when_disk_space_is_insufficient(): void
    {
        $this->app->instance(DiskSpace::class, new class implements DiskSpace
        {
            public function freeBytes(string $path): ?int
            {
                return 1024;
            }
        });

        try {
            app(BackupManager::class)->start('database');
            $this->fail('Trebuia refuzat.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Spațiu insuficient', $e->getMessage());
        }
        $this->assertSame(0, Backup::count());
    }

    public function test_documents_backup_roundtrip_with_checksums(): void
    {
        $private = storage_path('app/private');
        @mkdir($private.'/pacienti/1', 0750, true);
        file_put_contents($private.'/pacienti/1/document-fictiv.pdf', random_bytes(300000));
        file_put_contents($private.'/pacienti/1/gol.txt', '');

        $manager = app(BackupManager::class);
        $backup = $manager->start('documents');
        while (! $manager->step($backup, microtime(true) - 1)) {
            // pași minimi
        }
        $out = $this->deployRoot.'/restaurare-docs';
        $result = (new DocumentsArchiver)->verify(config('updates.shared_path').'/backups/'.$backup->file, $manager->key(), $out);

        $this->assertGreaterThanOrEqual(2, $result['files']);
        $this->assertFileEquals($private.'/pacienti/1/document-fictiv.pdf', $out.'/app_private/pacienti/1/document-fictiv.pdf');
        @unlink($private.'/pacienti/1/document-fictiv.pdf');
        @unlink($private.'/pacienti/1/gol.txt');
    }

    public function test_prune_keeps_configured_number(): void
    {
        config(['updates.backup.retain.database' => 2]);
        $manager = app(BackupManager::class);
        foreach (range(1, 3) as $_) {
            $manager->runToCompletion($manager->start('database'));
        }
        $this->assertSame(1, $manager->prune('database'));
        $this->assertSame(2, Backup::where('type', 'database')->count());
    }
}
