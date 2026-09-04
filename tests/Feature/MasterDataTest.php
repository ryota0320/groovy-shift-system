<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\IncomeTaxCategory;
use App\Enums\TransportationTaxType;
use App\Models\LateNightRateSetting;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffIncomeTaxSetting;
use App\Models\StaffStoreAssignment;
use App\Models\StaffStoreTransportationFee;
use App\Models\StaffWageRate;
use App\Models\Store;
use App\Models\StoreHoliday;
use App\Models\User;
use App\Services\StaffInitialImportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Covers MST-001 through MST-021 and IMP-001 through IMP-009. */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_business_hours_are_required_and_can_be_updated(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), [
                'name' => '開店時間未入力店',
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['opening_time', 'closing_time']);

        $store = Store::factory()->create([
            'opening_time' => '18:30',
            'closing_time' => '02:30',
        ]);

        $this->actingAs($admin)
            ->get(route('stores.edit', $store))
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.opening_time', '18:30')
                ->where('store.closing_time', '02:30'));

        $this->actingAs($admin)
            ->put(route('stores.update', $store), [
                'name' => $store->name,
                'opening_time' => '19:15',
                'closing_time' => '03:15',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('19:15', substr($store->fresh()->opening_time, 0, 5));
        $this->assertSame('03:15', substr($store->fresh()->closing_time, 0, 5));
    }

    public function test_store_holidays_are_displayed_in_date_ascending_order(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();

        foreach (['2026-09-30', '2026-09-28', '2026-09-29'] as $holidayDate) {
            StoreHoliday::query()->create([
                'store_id' => $store->id,
                'holiday_date' => $holidayDate,
            ]);
        }
        StoreHoliday::query()->create([
            'store_id' => $store->id,
            'holiday_date' => '2026-10-01',
        ]);

        $this->actingAs($admin)
            ->get(route('stores.edit', [
                'store' => $store,
                'holiday_month' => '2026-09',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('holiday_month', '2026-09')
                ->where('holiday_month_label', '2026年9月')
                ->where('previous_holiday_month', '2026-08')
                ->where('next_holiday_month', '2026-10')
                ->has('store.holidays', 3)
                ->where('store.holidays.0.holiday_date', '2026-09-28')
                ->where('store.holidays.1.holiday_date', '2026-09-29')
                ->where('store.holidays.2.holiday_date', '2026-09-30'));

        $this->actingAs($admin)
            ->get(route('stores.edit', [
                'store' => $store,
                'holiday_month' => '2026-10',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('holiday_month', '2026-10')
                ->has('store.holidays', 1)
                ->where('store.holidays.0.holiday_date', '2026-10-01'));
    }

    public function test_store_holiday_date_must_match_the_selected_month(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();

        $this->actingAs($admin)
            ->post(route('stores.holidays.store', $store), [
                'holiday_month' => '2026-09',
                'holiday_date' => '2026-10-01',
            ])
            ->assertSessionHasErrors('holiday_date');

        $this->assertDatabaseCount('store_holidays', 0);
    }

    public function test_staff_index_orders_employees_then_part_time_staff_by_id(): void
    {
        $admin = User::factory()->create();
        $firstPartTime = Staff::factory()->partTime()->create(['name' => '先に登録したアルバイト']);
        $firstEmployee = Staff::factory()->employee()->create(['name' => '後に登録した社員']);
        $secondPartTime = Staff::factory()->partTime()->create(['name' => '後に登録したアルバイト']);
        $secondEmployee = Staff::factory()->employee()->create(['name' => '先に表示する社員']);

        $this->actingAs($admin)
            ->get(route('staffs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staffs/index')
                ->has('staffs.data', 4)
                ->missing('filters.date')
                ->where('staffs.data.0.id', $firstEmployee->id)
                ->where('staffs.data.1.id', $secondEmployee->id)
                ->where('staffs.data.2.id', $firstPartTime->id)
                ->where('staffs.data.3.id', $secondPartTime->id));
    }

    public function test_staff_index_is_paginated_in_groups_of_twenty_five(): void
    {
        $admin = User::factory()->create();
        Staff::factory()->partTime()->create([
            'hired_at' => '2020-01-01',
            'retired_at' => today()->subDay(),
        ]);
        $staffs = Staff::factory()->partTime()->count(26)->create([
            'hired_at' => null,
            'retired_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('staffs.index', ['status' => 'employed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('staffs.data', 25)
                ->where('staffs.current_page', 1)
                ->where('staffs.last_page', 2)
                ->where('staffs.per_page', 25)
                ->where('staffs.total', 26));

        $this->actingAs($admin)
            ->get(route('staffs.index', ['status' => 'employed', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('staffs.data', 1)
                ->where('staffs.current_page', 2)
                ->where('staffs.data.0.id', $staffs->last()->id));
    }

    public function test_staff_name_is_split_and_display_name_is_searchable(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create([
            'last_name' => '山田',
            'first_name' => '花子',
            'display_name' => 'はなちゃん',
        ]);

        $this->assertSame('山田 花子', $staff->name);
        $this->assertSame('山田 花子', $staff->full_name);
        $this->assertSame('はなちゃん', $staff->preferred_name);

        $this->actingAs($admin)
            ->get(route('staffs.index', ['search' => 'はなちゃん']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('staffs.data', 1)
                ->where('staffs.data.0.name', '山田 花子')
                ->where('staffs.data.0.display_name', 'はなちゃん'));
    }

    public function test_staff_creation_requires_and_creates_an_initial_store_assignment_atomically(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();

        $this->actingAs($admin)
            ->post(route('staffs.store'), [
                'last_name' => '所属未設定',
                'first_name' => 'スタッフ',
                'employment_type' => EmploymentType::PartTime->value,
                'hired_at' => '2026-09-01',
                'retired_at' => null,
            ])
            ->assertSessionHasErrors(['store_id', 'assignment_effective_from']);

        $this->assertDatabaseMissing('staffs', ['name' => '所属未設定 スタッフ']);

        $this->actingAs($admin)
            ->post(route('staffs.store'), [
                'last_name' => '初期所属あり',
                'first_name' => 'スタッフ',
                'employment_type' => EmploymentType::PartTime->value,
                'hired_at' => '2026-09-01',
                'retired_at' => null,
                'store_id' => $store->id,
                'assignment_effective_from' => '2026-09-01',
            ])
            ->assertSessionHasNoErrors();

        $staff = Staff::query()->where('name', '初期所属あり スタッフ')->firstOrFail();
        $this->assertSame('初期所属あり', $staff->last_name);
        $this->assertSame('スタッフ', $staff->first_name);
        $this->assertDatabaseHas('staff_store_assignments', [
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-09-01 00:00:00',
            'effective_to' => null,
        ]);
    }

    public function test_master_data_can_be_registered_and_updated_through_management_routes(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), [
                'name' => '新店舗',
                'opening_time' => '18:30',
                'closing_time' => '02:30',
                'is_active' => true,
            ])
            ->assertRedirect();
        $store = Store::query()->where('name', '新店舗')->firstOrFail();
        $this->assertSame('18:30', substr($store->opening_time, 0, 5));

        $this->actingAs($admin)
            ->post(route('stores.holidays.store', $store), [
                'holiday_date' => '2026-09-15',
            ])
            ->assertRedirect();
        $holiday = $store->holidays()->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('stores.holidays.destroy', [$store, $holiday]))
            ->assertRedirect();
        $this->assertDatabaseMissing('store_holidays', ['id' => $holiday->id]);

        $this->actingAs($admin)
            ->post(route('staffs.store'), [
                'last_name' => '社員',
                'first_name' => '太郎',
                'employment_type' => EmploymentType::Employee->value,
                'hired_at' => '2026-04-01',
                'retired_at' => null,
                'store_id' => $store->id,
                'assignment_effective_from' => '2026-04-01',
            ])
            ->assertRedirect();
        $employee = Staff::query()->where('name', '社員 太郎')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('staffs.account.store', $employee), [
                'name' => $employee->name,
                'email' => 'employee@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('users', [
            'staff_id' => $employee->id,
            'role' => 'employee',
        ]);

        $this->actingAs($admin)
            ->post(route('staffs.store'), [
                'last_name' => 'アルバイト',
                'first_name' => '花子',
                'employment_type' => EmploymentType::PartTime->value,
                'hired_at' => null,
                'retired_at' => null,
                'store_id' => $store->id,
                'assignment_effective_from' => '2026-04-01',
            ])
            ->assertRedirect();
        $partTime = Staff::query()->where('name', 'アルバイト 花子')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('staffs.wage-rates.store', $partTime), [
                'hourly_wage' => 1300,
                'effective_from' => '2026-04-01',
                'effective_to' => null,
            ])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('staffs.transportation-fees.store', $partTime), [
                'store_id' => $store->id,
                'amount_per_day' => 500,
                'tax_type' => TransportationTaxType::NonTaxable->value,
                'effective_from' => '2026-04-01',
                'effective_to' => null,
            ])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('staffs.income-tax-settings.store', $partTime), [
                'tax_category' => IncomeTaxCategory::Ko->value,
                'dependent_count' => 1,
                'effective_from' => '2026-04-01',
                'effective_to' => null,
            ])
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('late-night-rates.store'), [
                'amount_per_hour' => 350,
                'effective_from' => '2026-04-01',
                'effective_to' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_store_assignments', [
            'staff_id' => $partTime->id,
            'store_id' => $store->id,
        ]);
        $this->assertDatabaseHas('staff_wage_rates', [
            'staff_id' => $partTime->id,
            'hourly_wage' => 1300,
        ]);
        $this->assertDatabaseHas('staff_store_transportation_fees', [
            'staff_id' => $partTime->id,
            'amount_per_day' => 500,
            'tax_type' => 'non_taxable',
        ]);
        $this->assertDatabaseHas('staff_income_tax_settings', [
            'staff_id' => $partTime->id,
            'tax_category' => 'ko',
            'dependent_count' => 1,
        ]);
        $this->assertDatabaseHas('late_night_rate_settings', [
            'amount_per_hour' => 350,
        ]);
    }

    public function test_initial_seeder_creates_the_three_stores(): void
    {
        config()->set('initial-admin.email', null);
        config()->set('initial-admin.password', null);

        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['46', 'オニカイ', '蛸福'],
            Store::query()->pluck('name')->all(),
        );
    }

    public function test_disabling_a_store_keeps_its_related_past_data_available(): void
    {
        $store = Store::factory()->create(['name' => '過去店舗']);
        $holiday = StoreHoliday::query()->create([
            'store_id' => $store->id,
            'holiday_date' => '2026-08-01',
        ]);
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('stores.update', $store), [
                'name' => $store->name,
                'opening_time' => '17:00',
                'closing_time' => '10:00',
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($store->fresh()->is_active);
        $this->assertDatabaseHas('store_holidays', ['id' => $holiday->id]);
        $this->actingAs($admin)->get(route('stores.edit', $store))->assertOk();
    }

    public function test_overlapping_store_assignment_is_rejected(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->create();
        $store = Store::factory()->create();
        StaffStoreAssignment::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        $this->actingAs($admin)
            ->post(route('staffs.assignments.store', $staff), [
                'store_id' => $store->id,
                'effective_from' => '2026-06-30',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('staff_store_assignments', 1);
    }

    public function test_overlapping_wage_rate_is_rejected(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1200,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('staffs.wage-rates.store', $staff), [
                'hourly_wage' => 1300,
                'effective_from' => '2026-07-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('staff_wage_rates', 1);
    }

    public function test_overlapping_transportation_fee_is_rejected_per_store(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();
        $store = Store::factory()->create();
        StaffStoreTransportationFee::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'amount_per_day' => 500,
            'tax_type' => TransportationTaxType::NonTaxable,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $this->actingAs($admin)
            ->post(route('staffs.transportation-fees.store', $staff), [
                'store_id' => $store->id,
                'amount_per_day' => 600,
                'tax_type' => TransportationTaxType::Taxable->value,
                'effective_from' => '2026-12-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('staff_store_transportation_fees', 1);
    }

    public function test_overlapping_late_night_rate_is_rejected(): void
    {
        $admin = User::factory()->create();
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 300,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('late-night-rates.store'), [
                'amount_per_hour' => 350,
                'effective_from' => '2026-08-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('late_night_rate_settings', 1);
    }

    public function test_overlapping_income_tax_setting_is_rejected(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();
        StaffIncomeTaxSetting::query()->create([
            'staff_id' => $staff->id,
            'tax_category' => IncomeTaxCategory::Ko,
            'dependent_count' => 0,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $this->actingAs($admin)
            ->post(route('staffs.income-tax-settings.store', $staff), [
                'tax_category' => IncomeTaxCategory::Otsu->value,
                'dependent_count' => 0,
                'effective_from' => '2026-12-31',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('staff_income_tax_settings', 1);
    }

    public function test_employee_cannot_receive_part_time_only_settings(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->employee()->create();

        $this->actingAs($admin)
            ->post(route('staffs.wage-rates.store', $staff), [
                'hourly_wage' => 1200,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('staff');

        $this->actingAs($admin)
            ->post(route('staffs.income-tax-settings.store', $staff), [
                'tax_category' => IncomeTaxCategory::Ko->value,
                'dependent_count' => 0,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('staff');
    }

    public function test_invalid_effective_period_is_rejected(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();

        $this->actingAs($admin)
            ->post(route('staffs.wage-rates.store', $staff), [
                'hourly_wage' => 1200,
                'effective_from' => '2026-07-01',
                'effective_to' => '2026-06-30',
            ])
            ->assertSessionHasErrors('effective_to');
    }

    public function test_store_holiday_is_rejected_when_work_shift_exists(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();
        $staff = Staff::factory()->create(['hired_at' => null]);
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_date' => '2026-09-15',
            'shift_type' => 'time',
            'start_time' => '19:00',
        ]);

        $this->actingAs($admin)
            ->post(route('stores.holidays.store', $store), ['holiday_date' => '2026-09-15'])
            ->assertSessionHasErrors('holiday_date');

        $this->assertDatabaseMissing('store_holidays', [
            'store_id' => $store->id,
            'holiday_date' => '2026-09-15',
        ]);
    }

    public function test_staff_period_cannot_be_changed_to_exclude_existing_shift(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create(['hired_at' => null, 'retired_at' => null]);
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => null,
            'shift_date' => '2026-09-15',
            'shift_type' => 'off',
            'start_time' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('staffs.update', $staff), [
                'last_name' => $staff->last_name,
                'first_name' => $staff->first_name,
                'employment_type' => 'part_time',
                'hired_at' => null,
                'retired_at' => '2026-09-14',
            ])
            ->assertSessionHasErrors('hired_at');

        $this->assertNull($staff->fresh()->retired_at);
    }

    public function test_assignment_period_cannot_be_changed_to_exclude_existing_shift(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create();
        $staff = Staff::factory()->partTime()->create(['hired_at' => null]);
        $assignment = StaffStoreAssignment::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_date' => '2026-09-15',
            'shift_type' => 'time',
            'start_time' => '19:00',
        ]);

        $this->actingAs($admin)
            ->put(route('staffs.assignments.update', [$staff, $assignment]), [
                'store_id' => $store->id,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-09-14',
            ])
            ->assertSessionHasErrors('effective_from');

        $this->assertNull($assignment->fresh()->effective_to);
    }

    public function test_inactive_store_is_rejected_for_new_history_but_existing_history_can_be_updated(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();
        $store = Store::factory()->create(['is_active' => false]);
        $assignment = StaffStoreAssignment::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('staffs.assignments.store', $staff), [
                'store_id' => $store->id,
                'effective_from' => '2027-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('store_id');

        $this->actingAs($admin)
            ->post(route('staffs.transportation-fees.store', $staff), [
                'store_id' => $store->id,
                'amount_per_day' => 500,
                'tax_type' => 'non_taxable',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasErrors('store_id');

        $this->actingAs($admin)
            ->put(route('staffs.assignments.update', [$staff, $assignment]), [
                'store_id' => $store->id,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_part_time_with_payroll_histories_cannot_be_changed_to_employee(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create();
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1300,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('staffs.update', $staff), [
                'last_name' => $staff->last_name,
                'first_name' => $staff->first_name,
                'employment_type' => 'employee',
                'hired_at' => null,
                'retired_at' => null,
            ])
            ->assertSessionHasErrors('employment_type');

        $this->assertSame(EmploymentType::PartTime, $staff->fresh()->employment_type);
    }

    public function test_staff_initial_import_creates_staff_and_period_histories_from_csv(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create(['name' => '46']);
        $headers = implode(',', array_values(StaffInitialImportService::HEADERS));
        $row = implode(',', [
            'PT001', '山田', '花子', 'はなちゃん', 'アルバイト', '2026-04-01', '', '46', '2026-04-01', '',
            '1300', '2026-04-01', '', '500', '非課税', '2026-04-01', '', '甲欄', '1', '2026-04-01', '',
        ]);
        $file = UploadedFile::fake()->createWithContent('staffs.csv', "{$headers}\n{$row}\n");

        $this->actingAs($admin)
            ->post(route('staffs.import.store'), ['file' => $file])
            ->assertRedirect(route('staffs.index'))
            ->assertSessionHasNoErrors();

        $staff = Staff::query()->where('name', '山田 花子')->firstOrFail();
        $this->assertSame('はなちゃん', $staff->display_name);
        $this->assertDatabaseHas('staff_store_assignments', ['staff_id' => $staff->id, 'store_id' => $store->id]);
        $this->assertDatabaseHas('staff_wage_rates', ['staff_id' => $staff->id, 'hourly_wage' => 1300]);
        $this->assertDatabaseHas('staff_store_transportation_fees', [
            'staff_id' => $staff->id,
            'amount_per_day' => 500,
            'tax_type' => 'non_taxable',
        ]);
        $this->assertDatabaseHas('staff_income_tax_settings', [
            'staff_id' => $staff->id,
            'tax_category' => 'ko',
            'dependent_count' => 1,
        ]);
    }

    public function test_staff_initial_import_auto_numbers_blank_keys_defaults_dependents_and_accepts_date_separators(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create(['name' => '46']);
        $headers = implode(',', array_values(array_filter(
            StaffInitialImportService::HEADERS,
            fn (string $key): bool => $key !== 'staff_key',
            ARRAY_FILTER_USE_KEY,
        )));
        $partTime = implode(',', [
            '山田', '花子', '', 'アルバイト', '2026/4/1', '', '46', '2026-04-01', '',
            '1300', '2026/4/1', '', '', '', '', '', '甲欄', '', '2026-04-01', '',
        ]);
        $employee = implode(',', [
            '佐藤', '太郎', '', '社員', '2026-4-2', '', '46', '2026/4/2', '',
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'staffs.csv',
            "{$headers}\n{$partTime}\n{$employee}\n",
        );

        $this->actingAs($admin)
            ->post(route('staffs.import.store'), ['file' => $file])
            ->assertRedirect(route('staffs.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('staffs', 2);
        $partTimeStaff = Staff::query()->where('name', '山田 花子')->firstOrFail();
        $this->assertDatabaseHas('staff_income_tax_settings', [
            'staff_id' => $partTimeStaff->id,
            'tax_category' => 'ko',
            'dependent_count' => 0,
            'effective_from' => '2026-04-01 00:00:00',
        ]);
        $this->assertDatabaseHas('staffs', [
            'name' => '佐藤 太郎',
            'hired_at' => '2026-04-02 00:00:00',
        ]);
        $this->assertDatabaseHas('staff_store_assignments', [
            'staff_id' => $partTimeStaff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-04-01 00:00:00',
        ]);
    }

    public function test_staff_initial_import_groups_rows_only_when_the_same_key_is_entered(): void
    {
        $admin = User::factory()->create();
        $firstStore = Store::factory()->create(['name' => '46']);
        $secondStore = Store::factory()->create(['name' => '47']);
        $headers = implode(',', array_values(StaffInitialImportService::HEADERS));
        $first = implode(',', ['PT001', '山田', '花子', '', 'アルバイト', '', '', '46', '2026-04-01']);
        $second = implode(',', ['PT001', '山田', '花子', '', 'アルバイト', '', '', '47', '2026-04-01']);
        $file = UploadedFile::fake()->createWithContent(
            'staffs.csv',
            "{$headers}\n{$first}\n{$second}\n",
        );

        $this->actingAs($admin)
            ->post(route('staffs.import.store'), ['file' => $file])
            ->assertRedirect(route('staffs.index'))
            ->assertSessionHasNoErrors();

        $staff = Staff::query()->where('name', '山田 花子')->firstOrFail();
        $this->assertDatabaseCount('staffs', 1);
        $this->assertDatabaseHas('staff_store_assignments', [
            'staff_id' => $staff->id,
            'store_id' => $firstStore->id,
        ]);
        $this->assertDatabaseHas('staff_store_assignments', [
            'staff_id' => $staff->id,
            'store_id' => $secondStore->id,
        ]);
    }

    public function test_staff_initial_import_is_all_or_nothing_when_a_row_is_invalid(): void
    {
        $admin = User::factory()->create();
        $headers = implode(',', array_values(StaffInitialImportService::HEADERS));
        $valid = implode(',', ['PT001', '山田', '花子', '', 'アルバイト']);
        $invalid = implode(',', ['PT002', '佐藤', '太郎', '', '不明']);
        $file = UploadedFile::fake()->createWithContent('staffs.csv', "{$headers}\n{$valid}\n{$invalid}\n");

        $this->actingAs($admin)
            ->post(route('staffs.import.store'), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('staffs', 0);
    }

    public function test_staff_initial_import_reports_the_original_row_number_after_blank_rows(): void
    {
        $admin = User::factory()->create();
        $headers = implode(',', array_values(StaffInitialImportService::HEADERS));
        $valid = implode(',', ['PT001', '山田', '花子', '', 'アルバイト']);
        $invalid = implode(',', ['PT002', '佐藤', '太郎', '', '不明']);
        $file = UploadedFile::fake()->createWithContent(
            'staffs.csv',
            "{$headers}\n{$valid}\n\n{$invalid}\n",
        );

        $this->actingAs($admin)
            ->post(route('staffs.import.store'), ['file' => $file])
            ->assertSessionHasErrors([
                'file' => '4行目: 雇用区分は「社員」または「アルバイト」を指定してください。',
            ]);

        $this->assertDatabaseCount('staffs', 0);
    }

    public function test_staff_initial_import_accepts_cp932_csv(): void
    {
        $admin = User::factory()->create();
        $headers = implode(',', array_values(StaffInitialImportService::HEADERS));
        $row = implode(',', ['PT001', '山田', '花子', '', 'アルバイト']);
        $contents = mb_convert_encoding("{$headers}\n{$row}\n", 'CP932', 'UTF-8');
        $file = UploadedFile::fake()->createWithContent('staffs.csv', $contents);

        $this->actingAs($admin)
            ->post(route('staffs.import.store'), ['file' => $file])
            ->assertRedirect(route('staffs.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('staffs', [
            'name' => '山田 花子',
            'employment_type' => 'part_time',
        ]);
    }

    public function test_staff_initial_import_accepts_xlsx(): void
    {
        $admin = User::factory()->create();
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            array_values(StaffInitialImportService::HEADERS),
            ['EMP001', '佐藤', '太郎', '', '社員'],
        ]);
        $spreadsheet->getActiveSheet()->setCellValue('F2', ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-04-01')));
        $spreadsheet->getActiveSheet()->getStyle('F2')->getNumberFormat()->setFormatCode('m/d/yyyy');
        $path = tempnam(sys_get_temp_dir(), 'staff-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $file = new UploadedFile(
                $path,
                'staffs.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            );
            $this->actingAs($admin)
                ->post(route('staffs.import.store'), ['file' => $file])
                ->assertRedirect(route('staffs.index'))
                ->assertSessionHasNoErrors();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseHas('staffs', [
            'name' => '佐藤 太郎',
            'employment_type' => 'employee',
            'hired_at' => '2026-04-01 00:00:00',
        ]);
    }

    public function test_staff_initial_import_template_can_be_downloaded(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('staffs.import.template'))
            ->assertOk()
            ->assertDownload('staff-initial-import-template.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'staff-template-').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path);
            $sheet = $spreadsheet->getSheetByName('インポートデータ');
            $this->assertNotNull($sheet);
            $this->assertSame(
                array_values(StaffInitialImportService::HEADERS),
                $sheet->rangeToArray('A1:U1', null, true, false)[0],
            );
            $this->assertSame('山田', $sheet->getCell('B2')->getValue());
            $this->assertSame('花子', $sheet->getCell('C2')->getValue());
            $this->assertSame('はなちゃん', $sheet->getCell('D2')->getValue());
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }
}
