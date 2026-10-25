<?php

use App\Core\Http\Middleware\EnsurePanelAccess;
use App\Core\Http\Middleware\RequireReauthentication;
use App\Core\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'cnp']);
    })->create();
