<?php

namespace App\Modules\Backup\Console;

use App\Modules\Backup\BackupManager;
use Illuminate\Console\Command;
use Throwable;

class BackupRun extends Command
{
    protected $signature = 'sevalia:backup:run {type=database : database | documents} {--trigger=manual} {--no-verify : Nu verifica după creare} {--no-prune : Nu șterge backupurile vechi}';

    protected $description = 'Creează un backup criptat (bază de date sau documente), îl verifică și aplică retenția';

    public function handle(BackupManager $manager): int
    {
        try {
            $backup = $manager->runToCompletion($manager->start($this->argument('type'), $this->option('trigger')));
            $this->info("Backup creat: {$backup->file} (".BackupManager::human((int) $backup->size).').');
            if (! $this->option('no-verify')) {
                $manager->verify($backup);
                $this->info('Backup verificat: decriptare, autentificare și numărare reușite.');
            }
            if (! $this->option('no-prune')) {
                $removed = $manager->prune($backup->type);
                $this->line("Backupuri vechi eliminate: {$removed}.");
            }
        } catch (Throwable $e) {
            $this->error('Backup eșuat: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
