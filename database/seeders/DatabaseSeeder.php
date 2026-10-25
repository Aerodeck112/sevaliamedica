<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seederul de instalare: roluri, permisiuni și conținutul public preluat de pe site.
 * Datele demonstrative (fictive) sunt separate: `php artisan db:seed --class=Database\\Seeders\\Demo\\DemoSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('sevalia:sync-permissions');
        $this->call(ClinicPublicDataSeeder::class);
    }
}
