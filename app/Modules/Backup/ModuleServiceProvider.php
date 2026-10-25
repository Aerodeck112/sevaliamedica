<?php

namespace App\Modules\Backup;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Modules\Identity\Roles;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\DiskSpace;
use App\Modules\Updates\Support\NativeDiskSpace;
use Illuminate\Console\Scheduling\Schedule;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'backup';
    }

    public function label(): string
    {
        return 'Backup';
    }

    public function permissions(): array
    {
        return [
            'backups.manage' => 'Creează, verifică și descarcă backupurile criptate',
        ];
    }

    public function roleGrants(): array
    {
        return [Roles::ADMIN_TEHNIC => ['backups.manage']];
    }

    public function navigation(): array
    {
        return [new NavItem('Backupuri', 'panou.backups.index', 'backups.manage', 'Administrare', 'shield', 41)];
    }

    public function register(): void
    {
        parent::register();
        $this->app->bindIf(DiskSpace::class, NativeDiskSpace::class);
        $this->app->bindIf(DeploymentLayout::class, fn () => DeploymentLayout::fromConfig());
    }

    public function schedule(Schedule $schedule): void
    {
        // Comenzile programate nu rulează în modul mentenanță (deci nici în timpul unei actualizări).
        $schedule->command('sevalia:backup:run database --trigger=schedule')
            ->dailyAt((string) config('updates.backup.daily_at', '02:30'))
            ->withoutOverlapping(180)->onOneServer();
        $schedule->command('sevalia:backup:run documents --trigger=schedule')
            ->weeklyOn(0, '03:30')->withoutOverlapping(240);
    }
}
