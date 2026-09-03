<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Services\BusinessDateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Covers DASH-001 through DASH-003 and DASH-005 through DASH-007. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_the_current_business_date_before_noon(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-09-05 11:30:00', 'Asia/Tokyo'));

        try {
            $user = User::factory()->create();
            $store = Store::factory()->create();
            $staff = Staff::factory()->create();
            Shift::query()->create([
                'staff_id' => $staff->id,
                'store_id' => $store->id,
                'shift_date' => '2026-09-04',
                'shift_type' => 'time',
                'start_time' => '19:00',
            ]);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('today', '2026-09-04')
                    ->where('today_shift_count', 1));
        } finally {
            Date::setTestNow();
        }
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_first_active_store_is_selected_by_default(): void
    {
        $user = User::factory()->create();
        Store::factory()->create(['name' => '無効店舗', 'is_active' => false]);
        $secondStore = Store::factory()->create(['name' => 'B店舗']);
        $firstStore = Store::factory()->create(['name' => 'A店舗']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('selected_store_id', $firstStore->id)
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('stores', 2)
                ->where('selected_store.id', $firstStore->id)
                ->where('today_shift_count', 0));

        $this->assertNotSame($firstStore->id, $secondStore->id);
    }

    public function test_selected_store_is_persisted_and_controls_dashboard_and_shift_pages(): void
    {
        $user = User::factory()->create();
        $businessDate = app(BusinessDateService::class)->current();
        Store::factory()->create(['name' => 'A店舗']);
        $selectedStore = Store::factory()->create(['name' => 'B店舗']);
        $staff = Staff::factory()->create([
            'hired_at' => null,
            'retired_at' => null,
        ]);
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $selectedStore->id,
            'shift_date' => $businessDate->toDateString(),
            'shift_type' => 'time',
            'start_time' => '19:00',
        ]);

        $this->actingAs($user)
            ->put(route('selected-store.update'), [
                'store_id' => $selectedStore->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('selected_store_id', $selectedStore->id);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_store.id', $selectedStore->id)
                ->where('today_shift_count', 1));

        $this->actingAs($user)
            ->get(route('shifts.monthly', ['month' => $businessDate->format('Y-m')]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_store.id', $selectedStore->id));

        $this->actingAs($user)
            ->get(route('shifts.daily', ['date' => $businessDate->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_store.id', $selectedStore->id));
    }

    public function test_attendance_missing_count_excludes_staff_with_today_attendance(): void
    {
        $user = User::factory()->create();
        $businessDate = app(BusinessDateService::class)->current();
        $store = Store::factory()->create(['name' => 'A店舗']);
        $otherStore = Store::factory()->create(['name' => 'Z店舗']);
        $missingStaff = Staff::factory()->create();
        $attendedStaff = Staff::factory()->create();
        $offStaff = Staff::factory()->create();
        $otherStoreStaff = Staff::factory()->create();
        $unscheduledStaff = Staff::factory()->create();

        foreach ([$missingStaff, $attendedStaff, $offStaff, $otherStoreStaff, $unscheduledStaff] as $staff) {
            $staff->storeAssignments()->create([
                'store_id' => $store->id,
                'effective_from' => $businessDate->subMonth()->toDateString(),
            ]);
        }
        foreach ([$missingStaff, $attendedStaff] as $staff) {
            Shift::query()->create([
                'staff_id' => $staff->id,
                'store_id' => $store->id,
                'shift_date' => $businessDate->toDateString(),
                'shift_type' => 'time',
                'start_time' => '19:00',
            ]);
        }
        Shift::query()->create([
            'staff_id' => $offStaff->id,
            'store_id' => null,
            'shift_date' => $businessDate->toDateString(),
            'shift_type' => 'off',
        ]);
        Shift::query()->create([
            'staff_id' => $otherStoreStaff->id,
            'store_id' => $otherStore->id,
            'shift_date' => $businessDate->toDateString(),
            'shift_type' => 'help',
        ]);
        AttendanceRecord::factory()->create([
            'staff_id' => $attendedStaff->id,
            'store_id' => $store->id,
            'work_date' => $businessDate->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('today_shift_count', 2)
                ->where('attendance_missing_count', 1)
                ->where('today_assigned_count', 5)
                ->where('today_assigned_working_count', 2)
                ->where('today_help_count', 0)
                ->where('today_off_count', 1)
                ->where('today_other_store_count', 1)
                ->where('today_unscheduled_count', 1));
    }

    public function test_inactive_store_cannot_be_selected_as_current_store(): void
    {
        $user = User::factory()->create();
        $inactiveStore = Store::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->put(route('selected-store.update'), [
                'store_id' => $inactiveStore->id,
            ])
            ->assertSessionHasErrors('store_id')
            ->assertSessionMissing('selected_store_id');
    }

    public function test_shift_pages_have_an_explicit_unselected_state_when_all_stores_are_inactive(): void
    {
        $user = User::factory()->create();
        Store::factory()->create(['name' => '過去店舗', 'is_active' => false]);

        $this->actingAs($user)
            ->get(route('shifts.monthly', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_store', null)
                ->has('stores', 1));

        $this->actingAs($user)
            ->get(route('shifts.daily', ['date' => '2026-09-01']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_store', null)
                ->has('stores', 1));
    }
}
