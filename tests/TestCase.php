<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Artisan::call('sevalia:sync-permissions');
    }

    /**
     * Utilizator autentificat în panou, cu MFA trecut, având rolurile date.
     */
    protected function actingAsRole(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->withMfa()->create($attributes);
        $user->syncRoles((array) $roles);
        $this->actingAs($user)->withSession(['auth.started_at' => time(), 'mfa.passed' => true]);

        return $user;
    }

    /** Marchează reconfirmarea identității ca recentă, pentru rutele cu middleware „reauth”. */
    protected function withRecentReauth(): static
    {
        return $this->withSession(['auth.started_at' => time(), 'mfa.passed' => true, 'auth.reauth_at' => time()]);
    }
}
