<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_admin_is_created_from_configuration(): void
    {
        config()->set('initial-admin', [
            'name' => '開発管理者',
            'email' => 'admin@example.com',
            'password' => 'local-secret',
        ]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame('開発管理者', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('local-secret', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_initial_admin_is_not_created_without_credentials(): void
    {
        config()->set('initial-admin', [
            'name' => null,
            'email' => null,
            'password' => null,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
