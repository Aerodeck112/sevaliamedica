<?php

namespace App\Modules\Updates\Console;

use App\Modules\Updates\Support\AppHealth;
use Illuminate\Console\Command;

/**
 * Verificare de funcționare a versiunii care rulează comanda. Folosită de updater după activare (într-un
 * proces separat, cu noul cod) și utilă pentru monitorizare din cron.
 */
class Health extends Command
{
    protected $signature = 'sevalia:health {--json : Rezultat JSON pe o singură linie}';

    protected $description = 'Verifică pornirea aplicației: bază de date, migrări, stocare, chei';

    public function handle(AppHealth $health): int
    {
        $checks = $health->checks();

        $ok = AppHealth::passed($checks);
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
