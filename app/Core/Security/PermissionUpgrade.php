<?php

namespace App\Core\Security;

use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Settings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * După o actualizare, permisiunile declarate de modulele noi trebuie create și acordate rolurilor implicite —
 * altfel meniurile noi nu ar fi vizibile nimănui. Rulează din cron (codul noii versiuni), o singură dată pe versiune.
 * Se acordă doar permisiunile care NU existau înainte: acordările implicite retrase manual nu sunt refăcute.
 */
class PermissionUpgrade
{
    public const SETTING = 'core.permissions_version';

    public function __construct(private readonly ModuleRegistry $registry, private readonly Settings $settings) {}

    /** @return list<string> permisiunile noi create (gol dacă versiunea era deja sincronizată) */
    public function runIfVersionChanged(): array
    {
        $version = (string) config('sevalia.version');
        if ($this->settings->get(self::SETTING) === $version) {
            return [];
        }
        $created = $this->run();
        $this->settings->set(self::SETTING, $version);

        return $created;
    }

    /** @return list<string> */
    public function run(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $existing = Permission::pluck('name')->all();
        $new = array_values(array_diff(array_keys($this->registry->permissions()), $existing));
        foreach ($new as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if ($new !== []) {
            foreach ($this->registry->roleGrants() as $roleName => $permissions) {
                $grant = array_values(array_intersect($permissions, $new));
                if ($grant !== []) {
                    Role::findOrCreate($roleName, 'web')->givePermissionTo($grant);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $new;
    }
}
