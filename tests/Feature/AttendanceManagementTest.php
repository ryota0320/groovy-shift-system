<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\ShiftType;
use App\Models\AttendanceRecord;
use App\Models\Payroll;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Covers ATT-001 through ATT-022, ATT-024 through ATT-030, ATT-032 through ATT-037 and EMP-001 through EMP-002. */
class AttendanceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_attendance_defaults_to_the_current_business_date(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-09-05 11:30:00', 'Asia/Tokyo'));

        try {
            $admin = User::factory()->create();
            $store = Store::factory()->create();

            $this->actingAs($admin)
                ->get(route('attendance.daily', ['store_id' => $store->id]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('date', '2026-09-04'));

            $this->actingAs($admin)
                ->get(route('attendance.daily', [
                    'store_id' => $store->id,
                    'date' => '2026-09-06',
                ]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('date', '2026-09-06'));
        } finally {
            Date::setTestNow();
        }
    }

    public function test_daily_attendance_saves_actual_datetimes_and_calculated_minutes(): void
    {
        [$admin, $store, $staff] = $this->context(withShift: true);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1260, 1575))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record = AttendanceRecord::query()->firstOrFail();
        $this->assertSame('2026-09-04', $record->work_date->toDateString());
        $this->assertSame('2026-09-04 21:00:00', $record->clock_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 02:15:00', $record->clock_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(315, $record->working_minutes);
        $this->assertSame(255, $record->late_night_minutes);
    }

    public function test_early_morning_attendance_requires_the_previous_business_date(): void
    {
        [$admin, $store, $staff] = $this->context();

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), [
                'store_id' => $store->id,
                'work_date' => '2026-09-05',
                'holiday_confirmed' => false,
                'records' => [[
                    'staff_id' => $staff->id,
                    'clock_in_offset_minutes' => 60,
                    'clock_out_offset_minutes' => 300,
                ]],
            ])
            ->assertSessionHasErrors([
                'records.0.clock_in_offset_minutes',
                'records.0.clock_out_offset_minutes',
            ]);

        $this->assertDatabaseMissing('attendance_records', [
            'staff_id' => $staff->id,
            'work_date' => '2026-09-05',
        ]);
    }

    public function test_preparation_before_opening_and_checkout_after_next_ten_are_allowed(): void
    {
        [$admin, $store, $staff] = $this->context();

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 900, 2220))
            ->assertSessionHasNoErrors();

        $record = AttendanceRecord::query()->firstOrFail();
        $this->assertSame('2026-09-04 15:00:00', $record->clock_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 13:00:00', $record->clock_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(1320, $record->working_minutes);
    }

    public function test_non_quarter_hour_and_twenty_four_hour_shift_are_rejected(): void
    {
        [$admin, $store, $staff] = $this->context();

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1141, 1380))
            ->assertSessionHasErrors('records.0.clock_in_offset_minutes');
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 2580))
            ->assertSessionHasErrors('records.0.clock_out_offset_minutes');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_clock_out_before_clock_in_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->context();

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1380, 1200))
            ->assertSessionHasErrors('records.0.clock_out_offset_minutes');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_same_business_date_attendance_at_another_store_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->context();
        $otherStore = Store::factory()->create();
        AttendanceRecord::factory()->create([
            'staff_id' => $staff->id,
            'store_id' => $otherStore->id,
            'work_date' => '2026-09-04',
        ]);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasErrors('records.0.staff_id');

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_assigned_staff_without_shift_is_shown_and_can_enter_attendance(): void
    {
        [$admin, $store, $staff] = $this->context();

        $this->actingAs($admin)
            ->get(route('attendance.daily', ['store_id' => $store->id, 'date' => '2026-09-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('attendance/daily')
                ->where('staffs.0.staff_id', $staff->id)
                ->where('staffs.0.source', 'unplanned')
                ->where('staffs.0.shift.display', 'シフト未設定')
                ->where('staffs.0.editable', true)
                ->where('addable_staffs', []));

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_records', [
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'work_date' => '2026-09-04 00:00:00',
        ]);
    }

    public function test_daily_attendance_uses_employee_then_part_time_id_order(): void
    {
        [$admin, $store, $partTime] = $this->context();
        $employee = $this->staffForStore($store, EmploymentType::Employee);

        $this->actingAs($admin)
            ->get(route('attendance.daily', ['store_id' => $store->id, 'date' => '2026-09-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.staff_id', $employee->id)
                ->where('staffs.1.staff_id', $partTime->id)
                ->where('staffs.0.source', 'unplanned')
                ->where('staffs.1.source', 'unplanned'));
    }

    public function test_scheduled_help_staff_can_record_attendance_without_help_store_assignment(): void
    {
        [$admin, , $staff] = $this->context();
        $helpStore = Store::factory()->create();
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $helpStore->id,
            'shift_date' => '2026-09-04',
            'shift_type' => ShiftType::Time,
            'start_time' => '19:00',
        ]);

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $helpStore->id,
                'date' => '2026-09-04',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.staff_id', $staff->id)
                ->where('staffs.0.source', 'scheduled')
                ->where('staffs.0.eligible', true)
                ->where('staffs.0.editable', true));

        $this->actingAs($admin)
            ->put(
                route('attendance.daily.save'),
                $this->payload($helpStore, $staff, 1140, 1380),
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_records', [
            'staff_id' => $staff->id,
            'store_id' => $helpStore->id,
            'work_date' => '2026-09-04 00:00:00',
        ]);
    }

    public function test_staff_from_another_store_can_be_added_as_sudden_attendance(): void
    {
        [$admin, $homeStore, $staff] = $this->context();
        $helpStore = Store::factory()->create();

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $helpStore->id,
                'date' => '2026-09-04',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs', [])
                ->where('addable_staffs.0.id', $staff->id)
                ->where('addable_staffs.0.assignment_store_names', [$homeStore->name]));

        $this->actingAs($admin)
            ->put(
                route('attendance.daily.save'),
                $this->payload($helpStore, $staff, 1140, 1380),
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_records', [
            'staff_id' => $staff->id,
            'store_id' => $helpStore->id,
            'work_date' => '2026-09-04 00:00:00',
        ]);
    }

    public function test_scheduled_staff_can_be_marked_as_an_urgent_absence_from_daily_attendance(): void
    {
        [$admin, $store, $staff] = $this->context(withShift: true);

        $this->actingAs($admin)
            ->put(route('shifts.cell.save'), [
                'store_id' => $store->id,
                'staff_id' => $staff->id,
                'shift_date' => '2026-09-04',
                'shift_type' => ShiftType::Absence->value,
                'start_time' => null,
                'work_store_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => null,
            'shift_type' => ShiftType::Absence->value,
            'start_time' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $store->id,
                'date' => '2026-09-04',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.staff_id', $staff->id)
                ->where('staffs.0.shift.type', ShiftType::Absence->value)
                ->where('staffs.0.shift.display', '急な休み')
                ->where('staffs.0.editable', false)
                ->where('addable_staffs', []));

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasErrors('records.0.staff_id');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_registered_attendance_prevents_changing_the_shift_to_an_urgent_absence(): void
    {
        [$admin, $store, $staff] = $this->context(withShift: true);
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1260, 1380))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('shifts.cell.save'), [
                'store_id' => $store->id,
                'staff_id' => $staff->id,
                'shift_date' => '2026-09-04',
                'shift_type' => ShiftType::Absence->value,
                'start_time' => null,
                'work_store_id' => null,
            ])
            ->assertSessionHasErrors('shift_type');

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_type' => ShiftType::Time->value,
        ]);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_replacement_staff_can_be_scheduled_from_an_urgent_absence_on_daily_attendance(): void
    {
        [$admin, $store, $absentStaff] = $this->context(withShift: true);
        $homeStore = Store::factory()->create(['name' => '所属元店舗']);
        $replacement = $this->staffForStore($homeStore);

        $this->actingAs($admin)
            ->put(route('shifts.cell.save'), [
                'store_id' => $store->id,
                'staff_id' => $absentStaff->id,
                'shift_date' => '2026-09-04',
                'shift_type' => ShiftType::Absence->value,
                'start_time' => null,
                'work_store_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $store->id,
                'date' => '2026-09-04',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.staff_id', $absentStaff->id)
                ->where('staffs.0.shift.type', ShiftType::Absence->value)
                ->where('addable_staffs.0.id', $replacement->id)
                ->where('addable_staffs.0.assignment_store_names', [$homeStore->name]));

        $this->actingAs($admin)
            ->post(route('shifts.store'), [
                'store_id' => $store->id,
                'staff_id' => $replacement->id,
                'shift_date' => '2026-09-04',
                'shift_type' => ShiftType::Time->value,
                'start_time' => '20:00',
                'work_store_id' => $store->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'staff_id' => $replacement->id,
            'store_id' => $store->id,
            'shift_date' => '2026-09-04 00:00:00',
            'shift_type' => ShiftType::Time->value,
            'start_time' => '20:00',
        ]);

        $this->actingAs($admin)
            ->get(route('attendance.daily', [
                'store_id' => $store->id,
                'date' => '2026-09-04',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs', fn ($staffs): bool => collect($staffs)
                    ->contains(fn (array $staff): bool => $staff['staff_id'] === $replacement->id
                        && $staff['shift']['display'] === '20:00'
                        && $staff['editable'] === true))
                ->where('addable_staffs', []));
    }

    public function test_store_holiday_requires_explicit_confirmation_and_is_then_saved(): void
    {
        [$admin, $store, $staff] = $this->context();
        $store->holidays()->create(['holiday_date' => '2026-09-04']);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasErrors('holiday_confirmed');
        $this->assertDatabaseCount('attendance_records', 0);

        $payload = $this->payload($store, $staff, 1140, 1380);
        $payload['holiday_confirmed'] = true;
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_time_shift_difference_is_exposed_as_a_warning_but_does_not_block_save(): void
    {
        [$admin, $store, $staff] = $this->context(withShift: true);
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1275, 1380))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('attendance.daily', ['store_id' => $store->id, 'date' => '2026-09-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.attendance.warning', 'シフト予定21:00と実出勤21:15が異なります。'));
    }

    public function test_next_day_shift_and_actual_time_use_the_same_business_date_offset(): void
    {
        [$admin, $store, $staff] = $this->context();
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_date' => '2026-09-04',
            'shift_type' => ShiftType::Time,
            'start_time' => '01:00',
        ]);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1500, 1740))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('attendance.daily', ['store_id' => $store->id, 'date' => '2026-09-04']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staffs.0.shift.display', '1:00')
                ->where('staffs.0.attendance.clock_in_label', '1:00')
                ->where('staffs.0.attendance.warning', null));
    }

    public function test_early_and_sudden_attendance_have_no_shift_difference_warning(): void
    {
        [$admin, $store, $staff] = $this->context();
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_date' => '2026-09-04',
            'shift_type' => ShiftType::Early,
        ]);
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1275, 1380))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->get(route('attendance.daily', ['store_id' => $store->id, 'date' => '2026-09-04']))
            ->assertInertia(fn (Assert $page) => $page->where('staffs.0.attendance.warning', null));

        $record = AttendanceRecord::query()->firstOrFail();
        $record->delete();
        Shift::query()->delete();
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1275, 1380))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->get(route('attendance.daily', ['store_id' => $store->id, 'date' => '2026-09-04']))
            ->assertInertia(fn (Assert $page) => $page->where('staffs.0.attendance.warning', null));
    }

    public function test_daily_bulk_save_is_atomic(): void
    {
        [$admin, $store, $staff] = $this->context();
        $second = $this->staffForStore($store);
        $payload = $this->payload($store, $staff, 1140, 1380);
        $payload['records'][] = [
            'staff_id' => $second->id,
            'clock_in_offset_minutes' => 1380,
            'clock_out_offset_minutes' => 1200,
        ];

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $payload)
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_daily_bulk_save_rejects_duplicate_staff_rows(): void
    {
        [$admin, $store, $staff] = $this->context();
        $payload = $this->payload($store, $staff, 1140, 1380);
        $payload['records'][] = $payload['records'][0];

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $payload)
            ->assertSessionHasErrors('records.0.staff_id');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_new_attendance_outside_employment_or_assignment_period_is_rejected(): void
    {
        [$admin, $store, $staff] = $this->context();
        $staff->update(['hired_at' => '2026-09-05']);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasErrors('records.0.staff_id');

        $staff->update(['hired_at' => '2026-01-01']);
        $staff->storeAssignments()->update(['effective_from' => '2026-09-05']);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasErrors('records.0.staff_id');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_attendance_can_be_deleted_and_marks_calculated_payroll_for_recalculation(): void
    {
        [$admin, $store, $staff] = $this->context();
        $payroll = $this->payroll($staff);
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasNoErrors();
        $this->assertTrue($payroll->fresh()->needs_recalculation);

        $payroll->update(['needs_recalculation' => false]);
        $record = AttendanceRecord::query()->firstOrFail();
        $this->actingAs($admin)
            ->delete(route('attendance.destroy', $record))
            ->assertRedirect();

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertTrue($payroll->fresh()->needs_recalculation);
    }

    public function test_payroll_inputs_mark_existing_payrolls_for_recalculation(): void
    {
        [$admin, $store, $staff] = $this->context();
        $payroll = $this->payroll($staff);

        $this->actingAs($admin)->post(route('staffs.wage-rates.store', $staff), [
            'hourly_wage' => 1300,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($payroll->fresh()->needs_recalculation);
    }

    public function test_part_time_staff_with_a_payroll_cannot_be_changed_to_employee(): void
    {
        [$admin, , $staff] = $this->context();
        $this->payroll($staff);

        $this->actingAs($admin)->put(route('staffs.update', $staff), [
            'name' => $staff->name,
            'employment_type' => EmploymentType::Employee->value,
            'hired_at' => '2026-01-01',
            'retired_at' => null,
        ])->assertSessionHasErrors('employment_type');

        $this->assertSame(EmploymentType::PartTime, $staff->fresh()->employment_type);
    }

    public function test_employee_attendance_is_saved_and_monthly_summary_supports_store_and_all_stores(): void
    {
        [$admin, $store] = $this->context();
        $employee = $this->staffForStore($store, EmploymentType::Employee);
        $otherStore = Store::factory()->create();
        $employee->storeAssignments()->create([
            'store_id' => $otherStore->id,
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $employee, 1020, 1500))
            ->assertSessionHasNoErrors();
        AttendanceRecord::factory()->create([
            'staff_id' => $employee->id,
            'store_id' => $otherStore->id,
            'work_date' => '2026-09-05',
            'clock_in_at' => '2026-09-05 17:00:00',
            'clock_out_at' => '2026-09-06 01:00:00',
            'working_minutes' => 480,
            'late_night_minutes' => 180,
        ]);

        $summaries = app(AttendanceSummaryService::class);
        $storeSummary = $summaries->employeeMonthly(Carbon::parse('2026-09-01'), $store);
        $allSummary = $summaries->employeeMonthly(Carbon::parse('2026-09-01'));

        $this->assertSame(1, $storeSummary[0]['attendance_days']);
        $this->assertSame(480, $storeSummary[0]['working_minutes']);
        $this->assertSame(2, $allSummary[0]['attendance_days']);
        $this->assertSame(960, $allSummary[0]['working_minutes']);
    }

    public function test_inactive_store_rejects_new_attendance_but_allows_existing_correction(): void
    {
        [$admin, $store, $staff] = $this->context();
        $store->update(['is_active' => false]);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1140, 1380))
            ->assertSessionHasErrors('records.0.staff_id');

        $record = AttendanceRecord::factory()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'work_date' => '2026-09-04',
        ]);
        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), $this->payload($store, $staff, 1200, 1380))
            ->assertSessionHasNoErrors();

        $this->assertSame('20:00', $record->fresh()->clock_in_at->format('H:i'));
    }

    public function test_daily_page_has_an_explicit_unselected_state_when_there_are_no_stores(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('attendance.daily', ['date' => '2026-09-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('attendance/daily')
                ->where('selected_store', null)
                ->where('staffs', [])
                ->where('addable_staffs', [])
                ->where('summary.attendance_count', 0));
    }

    public function test_staff_and_assignment_periods_cannot_exclude_existing_attendance(): void
    {
        [$admin, $store, $staff] = $this->context();
        AttendanceRecord::factory()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'work_date' => '2026-09-04',
        ]);
        $assignment = $staff->storeAssignments()->firstOrFail();

        $this->actingAs($admin)->put(route('staffs.update', $staff), [
            'name' => $staff->name,
            'employment_type' => EmploymentType::PartTime->value,
            'hired_at' => '2026-09-05',
            'retired_at' => null,
        ])->assertSessionHasErrors('hired_at');
        $this->actingAs($admin)->put(route('staffs.assignments.update', [$staff, $assignment]), [
            'store_id' => $store->id,
            'effective_from' => '2026-09-05',
            'effective_to' => null,
        ])->assertSessionHasErrors('effective_from');
    }

    /** @return array{User, Store, Staff} */
    private function context(bool $withShift = false): array
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();
        $staff = $this->staffForStore($store);

        if ($withShift) {
            Shift::query()->create([
                'staff_id' => $staff->id,
                'store_id' => $store->id,
                'shift_date' => '2026-09-04',
                'shift_type' => ShiftType::Time,
                'start_time' => '21:00',
            ]);
        }

        return [$admin, $store, $staff];
    }

    private function staffForStore(Store $store, EmploymentType $type = EmploymentType::PartTime): Staff
    {
        $staff = Staff::factory()->create([
            'employment_type' => $type,
            'hired_at' => '2026-01-01',
            'retired_at' => null,
        ]);
        $staff->storeAssignments()->create([
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        return $staff;
    }

    /** @return array<string, mixed> */
    private function payload(Store $store, Staff $staff, int $clockIn, int $clockOut): array
    {
        return [
            'store_id' => $store->id,
            'work_date' => '2026-09-04',
            'holiday_confirmed' => false,
            'records' => [[
                'staff_id' => $staff->id,
                'clock_in_offset_minutes' => $clockIn,
                'clock_out_offset_minutes' => $clockOut,
            ]],
        ];
    }

    private function payroll(Staff $staff): Payroll
    {
        return Payroll::query()->create([
            'staff_id' => $staff->id,
            'year' => 2026,
            'month' => 9,
            'payment_date' => '2026-10-10',
            'tax_year' => 2026,
            'calculated_at' => now(),
        ]);
    }
}
