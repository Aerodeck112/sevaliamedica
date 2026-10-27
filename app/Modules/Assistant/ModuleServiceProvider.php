<?php

namespace App\Modules\Assistant;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use Illuminate\Support\Facades\Route;

/**
 * Asistentul Sevalia de pe site: răspunsuri din conținutul public al siteului, fără servicii AI externe și fără
 * păstrarea întrebărilor. Ruta folosește grupul „site” (fără sesiune și fără cookie-uri).
 */
class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'asistent';
    }

    public function label(): string
    {
        return 'Asistent site';
    }

    public function boot(): void
    {
        parent::boot();
        if (! $this->app->routesAreCached()) {
            Route::middleware('site')->group(__DIR__.'/routes/site.php');
        }
    }
}
