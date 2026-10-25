<?php

namespace App\Modules\Updates;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Modules\Identity\Roles;
use App\Modules\Updates\Package\PackageExtractor;
use App\Modules\Updates\Package\PackageInspector;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\DiskSpace;
use App\Modules\Updates\Support\HealthChecker;
use App\Modules\Updates\Support\NativeDiskSpace;
use App\Modules\Updates\Support\ReleaseHealthChecker;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'updates';
    }

    public function label(): string
    {
        return 'Actualizări';
    }

    public function permissions(): array
    {
        return [
            'updates.manage' => 'Încarcă și instalează pachete de actualizare semnate; decide recuperarea la eșec',
        ];
    }

    public function roleGrants(): array
    {
        return [Roles::ADMIN_TEHNIC => ['updates.manage']];
    }

    public function navigation(): array
    {
        return [new NavItem('Actualizări', 'panou.updates.index', 'updates.manage', 'Administrare', 'upload', 40)];
    }

    public function register(): void
    {
        parent::register();
        $this->app->bindIf(DeploymentLayout::class, fn () => DeploymentLayout::fromConfig());
        $this->app->bindIf(DiskSpace::class, NativeDiskSpace::class);
        $this->app->bindIf(HealthChecker::class, ReleaseHealthChecker::class);
        $this->app->bindIf(PackageInspector::class, fn () => PackageInspector::fromConfig());
        $this->app->bindIf(PackageExtractor::class, fn ($app) => new PackageExtractor($app->make(PackageInspector::class)));
    }
}
