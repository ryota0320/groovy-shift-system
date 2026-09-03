<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'role:admin'])
            ->get('/_test/admin-only', fn () => response()->noContent());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/_test/admin-only')->assertRedirect(route('login'));
    }

    public function test_employee_cannot_access_admin_route(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee)->get('/_test/admin-only')->assertForbidden();
    }

    public function test_admin_can_access_admin_route(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/_test/admin-only')->assertNoContent();
    }
}
