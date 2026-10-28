<?php

use App\Core\Http\Middleware\EnsurePanelAccess;
use App\Core\Http\Middleware\RequireReauthentication;
use App\Core\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Core/Console',
        ...(glob(__DIR__.'/../app/Modules/*/Console', GLOB_ONLYDIR) ?: []),
    ])
    ->withSchedule(function (Schedule $schedule): void {
        // Coada de joburi procesată din cron, în loturi scurte, fără suprapunere.
        $schedule->command('queue:work --stop-when-empty --max-time=50 --max-jobs=100 --tries=5 --backoff=30,120,600')
            ->everyMinute()->withoutOverlapping(5)->runInBackground(false);
        $schedule->command('queue:prune-failed --hours=720')->daily();
        $schedule->command('auth:clear-resets')->hourly();
        // După o actualizare: permisiunile modulelor noi (o dată pe versiune, fără a reface acordările retrase).
        $schedule->call(fn () => app(\App\Core\Security\PermissionUpgrade::class)->runIfVersionChanged())
            ->everyMinute()->name('permisiuni-dupa-actualizare')->withoutOverlapping(5);
        // public_html/.htaccess trebuie să conțină regulile SEVALIA (altfel paginile dau 404): verificare și reparare.
        $schedule->call(function (): void {
            $public = config('updates.public_path');
            if ($public && is_file($template = base_path('deploy/public_html/.htaccess'))) {
                \App\Core\Support\Htaccess::write($public, (string) file_get_contents($template));
            }
        })->everyFiveMinutes()->name('htaccess-sevalia')->withoutOverlapping(5);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->alias([
            'panou' => EnsurePanelAccess::class,
            'reauth' => RequireReauthentication::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('panou.dashboard'));
        $middleware->encryptCookies(except: []);
        // În mentenanță (ex. în timpul unei actualizări) rămân accesibile doar autentificarea și tabul Actualizări.
        $middleware->preventRequestsDuringMaintenance(except: [
            'panou/actualizari', 'panou/actualizari/*', 'panou/autentificare', 'panou/autentificare/*',
            'panou/reconfirmare', 'panou/deconectare',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'cnp']);
    })->create();

// O versiune instalată din releases/ își citește .env și storage din directorul „shared” (vezi
// docs/ARHITECTURA.md §4). Fișierul .shared-path este scris de pasul „link_shared” al actualizării.
$sharedPathFile = dirname(__DIR__).'/.shared-path';
$shared = is_file($sharedPathFile) ? trim((string) file_get_contents($sharedPathFile)) : (string) getenv('SEVALIA_SHARED_PATH');
if ($shared !== '' && is_dir($shared) && is_file($shared.'/.env')) {
    $app->useEnvironmentPath($shared);
    $app->useStoragePath($shared.'/storage');
}

return $app;
