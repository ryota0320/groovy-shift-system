<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffStoreAssignment;
use App\Models\Store;
use App\Models\StoreHoliday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Covers SFT-001 through SFT-010 and SFT-015. */
class ShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_whole_hour_from_midnight_to_23_is_accepted(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();

        foreach (range(0, 23) as $hour) {
            $date = sprintf('2026-09-%02d', $hour + 1);
            $this->actingAs($admin)
                ->post(route('shifts.store'), $this->payload(
                    $store,
                    $staff,
                    $date,
                    'time',
                    sprintf('%02d:00', $hour),
                ))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('shifts', 24);
    }

    public function test_minutes_other_than_zero_are_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'time',
                '19:30',
            ))
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_early_shift_is_saved_without_start_time(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'early',
            ))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_type' => 'early',
            'start_time' => null,
        ]);
    }

    public function test_off_shift_is_global_and_saved_without_store_or_start_time(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'off',
            ))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => null,
            'shift_type' => 'off',
            'start_time' => null,
        ]);
    }

    public function test_off_then_work_on_the_same_business_date_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => null,
            'shift_date' => '2026-09-01',
            'shift_type' => 'off',
            'start_time' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'time',
                '19:00',
            ))
            ->assertSessionHasErrors('shift_type');

        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_work_then_off_on_the_same_business_date_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $this->createWorkShift($store, $staff, '2026-09-01');

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'off',
            ))
            ->assertSessionHasErrors('shift_type');

        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_work_at_another_store_on_the_same_business_date_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $otherStore = Store::factory()->create();
        StaffStoreAssignment::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $otherStore->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->createWorkShift($store, $staff, '2026-09-01');

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $otherStore,
                $staff,
                '2026-09-01',
                'time',
                '20:00',
            ))
            ->assertSessionHasErrors('shift_type');

        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_work_shift_on_a_store_holiday_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        StoreHoliday::query()->create([
            'store_id' => $store->id,
            'holiday_date' => '2026-09-01',
        ]);

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'time',
                '19:00',
            ))
            ->assertSessionHasErrors('shift_type');
    }

    public function test_work_shift_outside_store_assignment_period_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture('2026-08-31');

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'time',
                '19:00',
            ))
            ->assertSessionHasErrors('shift_type');
    }

    public function test_shift_outside_employment_period_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $staff->update(['retired_at' => '2026-08-31']);

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'off',
            ))
            ->assertSessionHasErrors('shift_type');
    }

    public function test_daily_bulk_save_creates_updates_and_clears_shifts_atomically(): void
    {
        [$admin, $store, $firstStaff] = $this->shiftFixture();
        $secondStaff = Staff::factory()->create(['hired_at' => null, 'retired_at' => null]);
        StaffStoreAssignment::query()->create([
            'staff_id' => $secondStaff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->createWorkShift($store, $firstStaff, '2026-09-01');

        $this->actingAs($admin)
            ->put(route('shifts.daily.save'), [
                'store_id' => $store->id,
                'shift_date' => '2026-09-01',
                'shifts' => [
                    [
                        'staff_id' => $firstStaff->id,
                        'shift_type' => null,
                        'start_time' => null,
                    ],
                    [
                        'staff_id' => $secondStaff->id,
                        'shift_type' => 'early',
                        'start_time' => null,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('shifts', [
            'staff_id' => $firstStaff->id,
            'shift_date' => '2026-09-01',
        ]);
        $this->assertDatabaseHas('shifts', [
            'staff_id' => $secondStaff->id,
            'shift_type' => 'early',
        ]);
    }

    public function test_daily_bulk_save_rolls_back_every_entry_when_one_is_invalid(): void
    {
        [$admin, $store, $validStaff] = $this->shiftFixture();
        $invalidStaff = Staff::factory()->create(['hired_at' => null, 'retired_at' => null]);

        $this->actingAs($admin)
            ->put(route('shifts.daily.save'), [
                'store_id' => $store->id,
                'shift_date' => '2026-09-01',
                'shifts' => [
                    [
                        'staff_id' => $validStaff->id,
                        'shift_type' => 'time',
                        'start_time' => '19:00',
                    ],
                    [
                        'staff_id' => $invalidStaff->id,
                        'shift_type' => 'time',
                        'start_time' => '20:00',
                    ],
                ],
            ])
            ->assertSessionHasErrors('shifts.1.shift_type');

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_monthly_and_daily_pages_expose_shift_calendar_data(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $this->createWorkShift($store, $staff, '2026-09-01');

        $this->actingAs($admin)
            ->get(route('shifts.monthly', ['store_id' => $store->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shifts/monthly')
                ->where('selected_store.id', $store->id)
                ->has('days', 30)
                ->has('staffs', 1)
                ->where('staffs.0.cells.0.start_time', '19:00'));

        $this->actingAs($admin)
            ->get(route('shifts.daily', ['store_id' => $store->id, 'date' => '2026-09-01']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shifts/daily')
                ->where('date', '2026-09-01')
                ->where('is_holiday', false)
                ->has('staffs', 1)
                ->where('staffs.0.start_time', '19:00'));
    }

    public function test_existing_shift_inconsistency_is_exposed_in_monthly_and_daily_views(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture('2026-09-30');
        $this->createWorkShift($store, $staff, '2026-09-15');
        StoreHoliday::query()->create([
            'store_id' => $store->id,
            'holiday_date' => '2026-09-15',
        ]);

        $this->actingAs($admin)
            ->get(route('shifts.monthly', ['store_id' => $store->id, 'month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.cells.14.display', '19')
                ->where('staffs.0.cells.14.inconsistency', '店休日に勤務シフトが残っています。'));

        $this->actingAs($admin)
            ->get(route('shifts.daily', ['store_id' => $store->id, 'date' => '2026-09-15']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.display', '19')
                ->where('staffs.0.inconsistency', '店休日に勤務シフトが残っています。'));
    }

    /** @return array{User, Store, Staff} */
    private function shiftFixture(?string $assignmentEnd = null): array
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();
        $staff = Staff::factory()->create(['hired_at' => null, 'retired_at' => null]);
        StaffStoreAssignment::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => $assignmentEnd,
        ]);

        return [$admin, $store, $staff];
    }

    /** @return array<string, int|string|null> */
    private function payload(
        Store $store,
        Staff $staff,
        string $date,
        string $type,
        ?string $startTime = null,
    ): array {
        return [
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'shift_date' => $date,
            'shift_type' => $type,
            'start_time' => $startTime,
        ];
    }

    private function createWorkShift(Store $store, Staff $staff, string $date): Shift
    {
        return Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_date' => $date,
            'shift_type' => 'time',
            'start_time' => '19:00',
        ]);
    }
}
