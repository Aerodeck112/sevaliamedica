<?php

namespace App\Providers;

use App\Core\Settings\Settings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        Carbon::setLocale('ro');
        Paginator::defaultView('pagination.panou');
        Paginator::defaultSimpleView('pagination.panou');

        // În dezvoltare și teste semnalăm accesul leneș la relații și atributele inexistente.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
