<?php

namespace App\Core\Console;

use App\Core\Modules\ModuleRegistry;
use App\Modules\Identity\Roles;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creează rolurile standard și permisiunile declarate de module și aplică acordările implicite.
 * Nu revocă acordări existente (cele făcute manual se păstrează), cu excepția opțiunii --reset.
 */
class SyncPermissions extends Command
{
    protected $signature = 'sevalia:sync-permissions {--reset : Readuce rolurile standard exact la acordările implicite}';

    protected $description = 'Sincronizează rolurile și permisiunile declarate de module';

    public function handle(ModuleRegistry $registry): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys($registry->permissions()) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (array_keys(Roles::all()) as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ($registry->roleGrants() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $this->option('reset') ? $role->syncPermissions($permissions) : $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Permisiuni: '.Permission::count().', roluri: '.Role::count().'.');

        return self::SUCCESS;
    }
}
