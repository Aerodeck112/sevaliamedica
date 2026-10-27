<?php

namespace Tests\Feature\Core;

use App\Core\Security\PermissionUpgrade;
use App\Core\Settings\Settings;
use App\Modules\Identity\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** După o actualizare, permisiunile modulelor noi apar automat, fără a reface acordările retrase manual. */
class PermissionUpgradeTest extends TestCase
{
    public function test_new_module_permissions_are_granted_once_per_version(): void
    {
        $this->artisan('sevalia:sync-permissions')->assertSuccessful();
        // Simulăm o instalare mai veche: permisiunea modulului Marketing nu exista.
        Permission::where('name', 'marketing.seo')->delete();
        // Administratorul retrăsese intenționat o acordare implicită existentă.
        Role::findByName(Roles::EDITOR_SITE)->revokePermissionTo('reviews.view');
        app(Settings::class)->set(PermissionUpgrade::SETTING, '0.9.0');

        $created = app(PermissionUpgrade::class)->runIfVersionChanged();
        $this->assertSame(['marketing.seo'], $created);
        $this->assertTrue(Role::findByName(Roles::MANAGER)->hasPermissionTo('marketing.seo'));
        $this->assertTrue(Role::findByName(Roles::EDITOR_SITE)->hasPermissionTo('marketing.seo'));
        $this->assertFalse(Role::findByName(Roles::RECEPTIE)->hasPermissionTo('marketing.seo'));
        $this->assertFalse(Role::findByName(Roles::EDITOR_SITE)->hasPermissionTo('reviews.view'), 'acordarea retrasă rămâne retrasă');
        $this->assertSame(config('sevalia.version'), app(Settings::class)->get(PermissionUpgrade::SETTING));

        // A doua rulare (cron la minut) nu mai face nimic.
        Permission::where('name', 'marketing.seo')->delete();
        $this->assertSame([], app(PermissionUpgrade::class)->runIfVersionChanged());
    }
}
