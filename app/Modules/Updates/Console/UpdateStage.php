<?php

namespace App\Modules\Updates\Console;

use App\Modules\Updates\Package\PackageException;
use App\Modules\Updates\Updater\UpdateRunner;
use Illuminate\Console\Command;

class UpdateStage extends Command
{
    protected $signature = 'sevalia:update:stage {package : Calea fișierului ZIP}';

    protected $description = 'Încarcă și verifică un pachet de actualizare (semnătură, structură, compatibilitate)';

    public function handle(UpdateRunner $runner): int
    {
        $src = $this->argument('package');
        $copy = sys_get_temp_dir().'/sevalia-upload-'.bin2hex(random_bytes(4)).'.zip';
        if (! is_file($src) || ! copy($src, $copy)) {
            $this->error('Fișierul nu poate fi citit.');

            return self::FAILURE;
        }
        try {
            $run = $runner->prepare($copy);
        } catch (PackageException $e) {
            $this->error('Pachet respins: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Pachet verificat: {$run->from_version} → {$run->to_version} (semnat cu cheia {$run->key_id}).");
        foreach ($run->preflight as $check) {
            $this->line(sprintf('  [%s] %s %s', $check['status'], $check['label'], $check['detail'] ? '— '.$check['detail'] : ''));
        }
        $this->line("Pentru instalare: php artisan sevalia:update:run {$run->uuid} --start");

        return self::SUCCESS;
    }
}
