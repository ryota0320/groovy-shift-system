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

/** Covers SFT-001 through SFT-010 and SFT-015 through SFT-028. */
class ShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_hours_from_opening_time_through_next_morning_are_accepted(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();

        $allowedHours = [...range(17, 23), ...range(0, 10)];

        foreach ($allowedHours as $index => $hour) {
            $date = sprintf('2026-09-%02d', $index + 1);
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

        $this->assertDatabaseCount('shifts', count($allowedHours));
    }

    public function test_shift_start_before_the_work_store_opening_time_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $store->update(['opening_time' => '19:30']);

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'time',
                '19:00',
            ))
            ->assertSessionHasErrors('shift_type');

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-02',
                'time',
                '20:00',
            ))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-03',
                'time',
                '01:00',
            ))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('shifts', 2);
    }

    public function test_shift_start_after_the_work_store_closing_time_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $store->update([
            'opening_time' => '18:00',
            'closing_time' => '02:30',
        ]);

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'time',
                '02:00',
            ))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-02',
                'time',
                '03:00',
            ))
            ->assertSessionHasErrors('shift_type');

        $this->assertDatabaseCount('shifts', 1);
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

    public function test_urgent_absence_is_distinct_from_scheduled_off(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();

        $this->actingAs($admin)
            ->post(route('shifts.store'), $this->payload(
                $store,
                $staff,
                '2026-09-01',
                'absence',
            ))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => null,
            'shift_type' => 'absence',
            'start_time' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('shifts.daily', ['store_id' => $store->id, 'date' => '2026-09-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.shift_type', 'absence')
                ->where('staffs.0.display', '急休'));

        $this->actingAs($admin)
            ->get(route('shifts.monthly', ['store_id' => $store->id, 'month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.cells.0.shift_type', 'absence')
                ->where('staffs.0.cells.0.display', '急休'));
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

    public function test_staff_of_a_holiday_store_can_work_as_help_at_an_open_store(): void
    {
        [$admin, $holidayStore, $staff] = $this->shiftFixture();
        $helpStore = Store::factory()->create(['name' => '営業中店舗']);
        StoreHoliday::query()->create([
            'store_id' => $holidayStore->id,
            'holiday_date' => '2026-09-01',
        ]);

        $this->actingAs($admin)
            ->get(route('shifts.daily', [
                'store_id' => $holidayStore->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_holiday', true)
                ->where('staffs.0.id', $staff->id)
                ->where('staffs.0.editable', true)
                ->where('staffs.0.available_store_ids', [$helpStore->id]));

        $this->actingAs($admin)
            ->get(route('shifts.monthly', [
                'store_id' => $holidayStore->id,
                'month' => '2026-09',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('days.0.is_holiday', true)
                ->where('staffs.0.cells.0.editable', true)
                ->where('staffs.0.cells.0.available_store_ids', [$helpStore->id]));

        $this->actingAs($admin)
            ->put(route('shifts.daily.save'), [
                'store_id' => $holidayStore->id,
                'shift_date' => '2026-09-01',
                'shifts' => [[
                    'staff_id' => $staff->id,
                    'shift_type' => 'time',
                    'start_time' => '19:00',
                    'work_store_id' => $helpStore->id,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => $helpStore->id,
            'shift_date' => '2026-09-01 00:00:00',
            'shift_type' => 'time',
            'start_time' => '19:00',
        ]);

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $holidayStore->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.staff_id', $staff->id)
                ->where('staffs.0.shift.display', $helpStore->name.' 19:00')
                ->where('staffs.0.conflict_store', $helpStore->name)
                ->where('staffs.0.editable', false));

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $helpStore->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.staff_id', $staff->id)
                ->where('staffs.0.shift.display', '19:00')
                ->where('staffs.0.conflict_store', null)
                ->where('staffs.0.editable', true));

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), [
                'store_id' => $holidayStore->id,
                'work_date' => '2026-09-01',
                'holiday_confirmed' => true,
                'records' => [[
                    'staff_id' => $staff->id,
                    'clock_in_offset_minutes' => 1140,
                    'clock_out_offset_minutes' => 1380,
                ]],
            ])
            ->assertSessionHasErrors('records.0.staff_id');
    }

    public function test_holiday_store_staff_is_not_editable_when_every_store_is_closed(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        StoreHoliday::query()->create([
            'store_id' => $store->id,
            'holiday_date' => '2026-09-01',
        ]);

        $this->actingAs($admin)
            ->get(route('shifts.daily', [
                'store_id' => $store->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_holiday', true)
                ->where('staffs.0.id', $staff->id)
                ->where('staffs.0.editable', false)
                ->where('staffs.0.available_store_ids', []));
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
                        'work_store_id' => null,
                    ],
                    [
                        'staff_id' => $secondStaff->id,
                        'shift_type' => 'early',
                        'start_time' => null,
                        'work_store_id' => $store->id,
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
                        'work_store_id' => $store->id,
                    ],
                    [
                        'staff_id' => $invalidStaff->id,
                        'shift_type' => 'time',
                        'start_time' => '20:00',
                        'work_store_id' => $store->id,
                    ],
                ],
            ])
            ->assertSessionHasErrors('shifts.1.shift_type');

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_shift_can_be_saved_to_another_store_as_help_without_assignment(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $helpStore = Store::factory()->create(['name' => 'ヘルプ店']);

        $this->actingAs($admin)
            ->put(route('shifts.cell.save'), [
                'store_id' => $store->id,
                'staff_id' => $staff->id,
                'shift_date' => '2026-09-01',
                'shift_type' => 'time',
                'start_time' => '20:00',
                'work_store_id' => $helpStore->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => $helpStore->id,
            'shift_date' => '2026-09-01 00:00:00',
            'start_time' => '20:00',
        ]);

        $this->actingAs($admin)
            ->get(route('shifts.daily', [
                'store_id' => $store->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.store_id', $helpStore->id)
                ->where('staffs.0.display', 'ヘルプ店 20')
                ->where('staffs.0.available_store_ids', [$store->id, $helpStore->id])
                ->where('staffs.0.editable', true));
    }

    public function test_inactive_and_holiday_help_stores_are_rejected(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $inactiveStore = Store::factory()->create(['is_active' => false]);
        $holidayStore = Store::factory()->create();

        StoreHoliday::query()->create([
            'store_id' => $holidayStore->id,
            'holiday_date' => '2026-09-01',
        ]);

        foreach ([$inactiveStore, $holidayStore] as $invalidStore) {
            $this->actingAs($admin)
                ->put(route('shifts.cell.save'), [
                    'store_id' => $store->id,
                    'staff_id' => $staff->id,
                    'shift_date' => '2026-09-01',
                    'shift_type' => 'time',
                    'start_time' => '20:00',
                    'work_store_id' => $invalidStore->id,
                ])
                ->assertSessionHasErrors('shift_type');
        }

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_newly_added_active_store_is_exposed_as_a_shift_option_automatically(): void
    {
        [$admin, $store, $staff] = $this->shiftFixture();
        $newStore = Store::factory()->create(['name' => '新店舗']);

        $this->actingAs($admin)
            ->get(route('shifts.daily', [
                'store_id' => $store->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stores', fn ($stores): bool => collect($stores)
                    ->contains(fn (array $candidate): bool => $candidate['id'] === $newStore->id))
                ->where('staffs.0.available_store_ids', [$store->id, $newStore->id]));
    }

    public function test_other_store_staff_can_be_added_and_saved_as_a_replacement(): void
    {
        $admin = User::factory()->create();
        $targetStore = Store::factory()->create(['name' => '交代先店舗']);
        $homeStore = Store::factory()->create(['name' => '所属元店舗']);
        $absentStaff = Staff::factory()->create([
            'name' => '急休スタッフ',
            'hired_at' => null,
            'retired_at' => null,
        ]);
        StaffStoreAssignment::query()->create([
            'staff_id' => $absentStaff->id,
            'store_id' => $targetStore->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->createWorkShift($targetStore, $absentStaff, '2026-09-01');

        $replacement = Staff::factory()->create([
            'name' => '交代スタッフ',
            'hired_at' => null,
            'retired_at' => null,
        ]);
        StaffStoreAssignment::query()->create([
            'staff_id' => $replacement->id,
            'store_id' => $homeStore->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('shifts.daily', [
                'store_id' => $targetStore->id,
                'date' => '2026-09-01',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.id', $absentStaff->id)
                ->where('addable_staffs.0.id', $replacement->id)
                ->where('addable_staffs.0.assignment_store_names', [$homeStore->name])
                ->where('addable_staffs.0.available_store_ids', [$targetStore->id, $homeStore->id]));

        $this->actingAs($admin)
            ->put(route('shifts.daily.save'), [
                'store_id' => $targetStore->id,
                'shift_date' => '2026-09-01',
                'shifts' => [
                    [
                        'staff_id' => $absentStaff->id,
                        'shift_type' => 'absence',
                        'start_time' => null,
                        'work_store_id' => null,
                    ],
                    [
                        'staff_id' => $replacement->id,
                        'shift_type' => 'time',
                        'start_time' => '20:00',
                        'work_store_id' => $targetStore->id,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $absentStaff->id,
            'store_id' => null,
            'shift_type' => 'absence',
            'start_time' => null,
        ]);
        $this->assertDatabaseHas('shifts', [
            'staff_id' => $replacement->id,
            'store_id' => $targetStore->id,
            'shift_type' => 'time',
            'start_time' => '20:00',
        ]);
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
                ->where('stores.0.opening_time', '17:00')
                ->where('stores.0.closing_time', '10:00')
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

    public function test_monthly_staffs_use_default_order_and_store_specific_saved_order(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();
        $firstPartTime = Staff::factory()->partTime()->create(['name' => 'アルバイト1']);
        $firstEmployee = Staff::factory()->employee()->create(['name' => '社員1']);
        $secondPartTime = Staff::factory()->partTime()->create(['name' => 'アルバイト2']);
        $secondEmployee = Staff::factory()->employee()->create(['name' => '社員2']);

        foreach ([$firstPartTime, $firstEmployee, $secondPartTime, $secondEmployee] as $staff) {
            StaffStoreAssignment::query()->create([
                'staff_id' => $staff->id,
                'store_id' => $store->id,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('shifts.monthly', ['store_id' => $store->id, 'month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.id', $firstEmployee->id)
                ->where('staffs.1.id', $secondEmployee->id)
                ->where('staffs.2.id', $firstPartTime->id)
                ->where('staffs.3.id', $secondPartTime->id));

        $savedOrder = [
            $secondPartTime->id,
            $firstEmployee->id,
            $firstPartTime->id,
            $secondEmployee->id,
        ];
        $this->actingAs($admin)
            ->put(route('shifts.monthly.order.save'), [
                'store_id' => $store->id,
                'staff_ids' => $savedOrder,
            ])
            ->assertSessionHasNoErrors();

        foreach ($savedOrder as $position => $staffId) {
            $this->assertDatabaseHas('staff_store_display_orders', [
                'store_id' => $store->id,
                'staff_id' => $staffId,
                'position' => $position,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('shifts.monthly', ['store_id' => $store->id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.id', $savedOrder[0])
                ->where('staffs.1.id', $savedOrder[1])
                ->where('staffs.2.id', $savedOrder[2])
                ->where('staffs.3.id', $savedOrder[3]));

        $this->actingAs($admin)
            ->get(route('shifts.daily', ['store_id' => $store->id, 'date' => '2026-10-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.id', $savedOrder[0])
                ->where('staffs.1.id', $savedOrder[1])
                ->where('staffs.2.id', $savedOrder[2])
                ->where('staffs.3.id', $savedOrder[3]));
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
            'work_store_id' => in_array($type, ['time', 'early'], true)
                ? $store->id
                : null,
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
