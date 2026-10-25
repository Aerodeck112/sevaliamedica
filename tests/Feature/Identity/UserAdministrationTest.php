<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Roles;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    public function test_only_authorized_roles_manage_users(): void
    {
        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.users.index'))->assertForbidden();
    }

    public function test_user_management_requires_recent_reauthentication(): void
    {
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->get(route('panou.users.index'))->assertOk();
        $this->get(route('panou.users.create'))->assertRedirect(route('panou.reauth'));
    }

    public function test_admin_creates_user_with_role_and_reset_link(): void
    {
        Notification::fake();
        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->withRecentReauth()->post(route('panou.users.store'), [
            'name' => 'Ana Test', 'email' => 'Ana@Example.test', 'is_active' => 1, 'roles' => [Roles::RECEPTIE],
        ])->assertRedirect(route('panou.users.index'));

        $user = User::where('email', 'ana@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole(Roles::RECEPTIE));
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.created', 'subject_id' => $user->id]);
    }

    public function test_manager_cannot_grant_technical_admin(): void
    {
        $manager = $this->actingAsRole(Roles::MANAGER);
        $manager->givePermissionTo('users.manage');
        $this->withRecentReauth()->post(route('panou.users.store'), [
            'name' => 'X', 'email' => 'x@example.test', 'is_active' => 1, 'roles' => [Roles::ADMIN_TEHNIC],
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_technical_admin_has_no_medical_permissions_by_default(): void
    {
        $admin = $this->actingAsRole(Roles::ADMIN_TEHNIC);
        foreach ($admin->getAllPermissions() as $permission) {
            $this->assertDoesNotMatchRegularExpression('/^(medical|patients|rehab)\./', $permission->name);
        }
    }
}
