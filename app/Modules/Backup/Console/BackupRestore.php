<?php

namespace App\Modules\Backup\Console;

use App\Core\Audit\Audit;
use App\Modules\Backup\BackupManager;
use App\Modules\Backup\Dump\DocumentsArchiver;
use App\Modules\Backup\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Restaurare explicită. Implicit într-o bază de date de VERIFICARE, nu peste producție: restaurarea peste
 * baza curentă cere opțiunea --over-current și tastarea confirmării.
 */
class BackupRestore extends Command
{
    protected $signature = 'sevalia:backup:restore {uuid : Backupul de restaurat}
        {--database= : Baza de date țintă (trebuie să existe; pe cPanel se creează din „MySQL Databases”)}
        {--over-current : Restaurează peste baza de date curentă a aplicației (distructiv)}
        {--target-dir= : Pentru backupuri de documente: directorul în care se extrag fișierele}
        {--force : Fără întrebare interactivă (doar pentru baza de verificare)}';

    protected $description = 'Restaurează un backup criptat într-o bază de date sau un director ales';

    public function handle(BackupManager $manager): int
    {
        $backup = Backup::where('uuid', $this->argument('uuid'))->firstOrFail();

        if ($backup->type === 'documents') {
            $dir = $this->option('target-dir') ?: storage_path('app/restaurare-'.date('Ymd-His'));
            $result = (new DocumentsArchiver)->verify(config('updates.shared_path').'/backups/'.$backup->file, $manager->key(), $dir);
            Audit::log('backup.restored_documents', $backup, ['fisiere' => $result['files']]);
            $this->info("Documente restaurate în {$dir}: {$result['files']} fișiere.");

            return self::SUCCESS;
        }

        $current = $manager->connection()->getDatabaseName();
        $targetDb = $this->option('over-current') ? $current : $this->option('database');
        if (! $targetDb) {
            $this->error('Alegeți baza țintă cu --database=<nume> (recomandat: o bază goală de verificare) sau --over-current.');

            return self::FAILURE;
        }
        if ($targetDb === $current) {
            if (! $this->option('over-current')) {
                $this->error('Ținta este baza curentă; folosiți explicit --over-current.');

                return self::FAILURE;
            }
            $this->warn("ATENȚIE: restaurarea înlocuiește TOATE datele din baza curentă „{$current}” cu cele din backupul de la {$backup->started_at}.");
            $this->warn('Orice modificare făcută după acel moment se pierde. Puneți aplicația în mentenanță înainte.');
            if ($this->ask('Tastați RESTAUREAZĂ pentru a continua') !== 'RESTAUREAZĂ') {
                $this->line('Restaurare anulată.');

                return self::FAILURE;
            }
        } elseif (! $this->option('force') && ! $this->confirm("Restaurez în baza „{$targetDb}”? Conținutul ei existent va fi înlocuit.")) {
            return self::FAILURE;
        }

        $connection = $this->targetConnection($manager, $targetDb);
        try {
            $state = null;
            do {
                // Peste baza curentă: restaurare exactă (se elimină și tabelele apărute după backup).
                $state = $manager->restoreStep($backup, $connection, $state, microtime(true) + 30, exact: (bool) $this->option('over-current'));
                $this->output->write('.');
            } while (! $state['done']);
            $this->newLine();
        } catch (Throwable $e) {
            $this->error('Restaurare eșuată: '.$e->getMessage());
            Audit::log('backup.restore_failed', $backup, ['tinta' => $targetDb]);

            return self::FAILURE;
        }
        Audit::log('backup.restored', $backup, ['tinta' => $targetDb, 'instructiuni' => $state['executed']]);
        $this->info("Restaurare reușită în „{$targetDb}”: {$state['executed']} instrucțiuni.");

        return self::SUCCESS;
    }

    private function targetConnection(BackupManager $manager, string $database)
    {
        $base = $manager->connection()->getName();
        $name = 'sevalia_restore_target';
        config(["database.connections.{$name}" => array_merge(config("database.connections.{$base}"), ['database' => $database])]);
        DB::purge($name);

        return DB::connection($name);
    }
}
