<?php

namespace App\Providers;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Descoperă și înregistrează furnizorii modulelor din app/Modules/<Nume>/ModuleServiceProvider.php.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        $files = glob(app_path('Modules/*/ModuleServiceProvider.php')) ?: [];
        sort($files);
        foreach ($files as $file) {
            $this->app->register('App\\Modules\\'.basename(dirname($file)).'\\ModuleServiceProvider');
        }
    }
}
