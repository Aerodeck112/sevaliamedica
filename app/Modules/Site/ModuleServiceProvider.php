<?php

namespace App\Modules\Site;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\PublicBooking;
use App\Core\Http\Middleware\SecurityHeaders;
use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Modules\Site\Http\Controllers\PageController;
use App\Modules\Site\Services\FormBlock;
use App\Modules\Site\Services\FormSubmissionIntake;
use App\Modules\Site\Services\NoPublicBooking;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/**
 * Siteul public. Rutele publice folosesc grupul „site”, fără sesiune și fără cookie-uri: paginile pot fi puse în
 * cache și nu conțin date personale. Formularele folosesc un token semnat (vezi FormToken).
 */
class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'site';
    }

    public function label(): string
    {
        return 'Site public';
    }

    public function register(): void
    {
        parent::register();
        $this->app->scoped(FormBlock::class);
        // Implementarea implicită: dacă modulul de programări nu își leagă propria implementare, solicitările
        // se păstrează criptat în form_submissions și apar în „Mesaje din site”.
        $this->app->bindIf(AppointmentIntake::class, FormSubmissionIntake::class);
        $this->app->bindIf(PublicBooking::class, NoPublicBooking::class);
    }

    public function boot(): void
    {
        $router = $this->app['router'];
        $router->middlewareGroup('site', [SecurityHeaders::class, SubstituteBindings::class]);
        $this->loadViewsFrom(__DIR__.'/Views', 'site');

        if ($this->app->routesAreCached()) {
            return;
        }
        Route::middleware('site')->group(__DIR__.'/routes/public.php');
        // Ultima rută: orice URL public necunoscut altor module este căutat printre paginile CMS.
        Route::fallback([PageController::class, 'show'])->middleware('site');
    }
}
