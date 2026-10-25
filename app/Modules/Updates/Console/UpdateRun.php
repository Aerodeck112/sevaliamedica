<?php

namespace App\Modules\Updates\Console;

use App\Modules\Updates\Models\UpdateRun as Run;
use App\Modules\Updates\Updater\UpdateRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rulează sau reia o actualizare din linia de comandă (alternativă la tabul Actualizări, fără limita de timp
 * a cererilor web). Pașii sunt aceiași; o actualizare întreruptă se reia cu aceeași comandă.
 */
class UpdateRun extends Command
{
    protected $signature = 'sevalia:update:run {uuid : Identificatorul actualizării}
        {--start : Pornește o actualizare pregătită}
        {--retry : Reia pasul eșuat}
        {--restore-db : Restaurează baza de date din backupul de dinainte (după o migrare eșuată)}';

    protected $description = 'Rulează, reia sau recuperează o actualizare';

    public function handle(UpdateRunner $runner): int
    {
        $run = Run::where('uuid', $this->argument('uuid'))->firstOrFail();
        try {
            if ($this->option('start')) {
                $run = $runner->start($run);
            }
            if ($this->option('retry')) {
                $run = $runner->retry($run);
            }
            if ($this->option('restore-db')) {
                $this->warn($runner->restoreCheck($run)['detail']);
                if ($this->ask('Tastați RESTAUREAZĂ pentru a continua') !== 'RESTAUREAZĂ') {
                    return self::FAILURE;
                }
                while (! $runner->restoreDatabase($run)) {
                    $this->output->write('.');
                }
                $this->info(' Baza de date a fost restaurată; aplicația rulează versiunea '.$run->from_version.'.');

                return self::SUCCESS;
            }
            $run = $runner->runToEnd($run, function (Run $r) {
                $this->line('  '.($r->current_step ? Run::STEPS[$r->current_step] ?? $r->current_step : '').' — '.($r->step((string) $r->current_step)['status'] ?? ''));
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($run->status === 'completed') {
            $this->info("Actualizare încheiată: versiunea {$run->to_version} este activă.");

            return self::SUCCESS;
        }
        $this->error('Actualizarea nu s-a încheiat: '.Run::STATUSES[$run->status]);
        $this->line((string) $run->error);
        foreach ($runner->recoveryOptions($run) as $key => $option) {
            $this->line("  • {$option['label']} ({$key}): {$option['detail']}");
        }

        return self::FAILURE;
    }
}
