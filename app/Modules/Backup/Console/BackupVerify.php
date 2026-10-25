<?php

namespace App\Modules\Backup\Console;

use App\Modules\Backup\BackupManager;
use App\Modules\Backup\Models\Backup;
use Illuminate\Console\Command;
use Throwable;

class BackupVerify extends Command
{
    protected $signature = 'sevalia:backup:verify {uuid? : Backupul de verificat (implicit ultimul)}';

    protected $description = 'Verifică un backup: suma de control, decriptarea, autentificarea blocurilor și conținutul';

    public function handle(BackupManager $manager): int
    {
        $backup = $this->argument('uuid')
            ? Backup::where('uuid', $this->argument('uuid'))->firstOrFail()
            : Backup::whereIn('status', ['completed', 'verified'])->latest('id')->firstOrFail();
        try {
            $result = $manager->verify($backup);
            $this->info("Backupul {$backup->uuid} este valid.");
            $this->line(json_encode($result['footer'] ?? $result, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            $this->error('Verificare eșuată: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
