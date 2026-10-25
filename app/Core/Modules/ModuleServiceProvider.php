<?php

namespace App\Core\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Baza pentru furnizorul fiecărui modul din app/Modules/<Nume>.
 *
 * Convenții:
 *  - routes/public.php  rute publice (middleware „web”)
 *  - routes/panel.php   rute de panou sub /panou, nume „panou.”, autentificare + MFA obligatorii
 *  - Views/             vizualizări Blade, namespace = $this->key() (ex. view('scheduling::calendar'))
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Cheia scurtă a modulului (ex. „scheduling”). */
    abstract public function key(): string;

    /** Numele afișat al modulului. */
    abstract public function label(): string;

    /**
     * Permisiunile declarate de modul: ['cheie.permisiune' => 'Descriere în română'].
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Acordările implicite pe roluri: ['rol' => ['cheie.permisiune', ...]].
     * Sunt aplicate de comanda sevalia:sync-permissions, fără a elimina acordările făcute manual.
     *
     * @return array<string, list<string>>
     */
    public function roleGrants(): array
    {
        return [];
    }

    /**
     * Intrările de meniu din panou.
     *
     * @return list<NavItem>
     */
    public function navigation(): array
    {
        return [];
    }

    /**
     * Widgeturile modulului pentru ecranul principal.
     *
     * @return list<DashboardWidget>
     */
    public function dashboardWidgets(): array
    {
        return [];
    }

    /**
     * Taskurile programate ale modulului. Rulează din cronul unic `php artisan schedule:run` (la minut).
     * Folosiți ->withoutOverlapping() și loturi limitate: hostingul nu are procese permanente.
     */
    public function schedule(Schedule $schedule): void {}

    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->add($this);
    }

    public function boot(): void
    {
        $dir = $this->moduleDirectory();

        if (is_dir($dir.'/Views')) {
            $this->loadViewsFrom($dir.'/Views', $this->key());
        }

        if ($this->app->runningInConsole()) {
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        if (is_file($dir.'/routes/public.php')) {
            Route::middleware('web')->group($dir.'/routes/public.php');
        }

        if (is_file($dir.'/routes/panel.php')) {
            Route::middleware(['web', 'auth', 'panou'])
                ->prefix('panou')
                ->name('panou.')
                ->group($dir.'/routes/panel.php');
        }
    }

    protected function moduleDirectory(): string
    {
        return dirname((new \ReflectionClass($this))->getFileName());
    }
}
