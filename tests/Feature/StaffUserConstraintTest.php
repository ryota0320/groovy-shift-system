<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffUserConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_time_staff_cannot_have_a_user_account(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();

        $this->actingAs($admin)
            ->post(route('staffs.account.store', $staff), [
                'name' => $staff->name,
                'email' => 'part-time@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('staff');

        $this->assertDatabaseMissing('users', ['staff_id' => $staff->id]);
    }

    public function test_two_users_cannot_be_linked_to_the_same_staff(): void
    {
        $staff = Staff::factory()->employee()->create();
        User::factory()->create([
            'staff_id' => $staff->id,
            'role' => UserRole::Employee,
        ]);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'staff_id' => $staff->id,
            'role' => UserRole::Employee,
        ]);
    }

    public function test_employee_user_requires_a_staff_link(): void
    {
        $this->expectException(ValidationException::class);

        User::factory()->create([
            'staff_id' => null,
            'role' => UserRole::Employee,
        ]);
    }
}
