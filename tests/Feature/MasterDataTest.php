<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\IncomeTaxCategory;
use App\Enums\TransportationTaxType;
use App\Models\LateNightRateSetting;
use App\Models\Staff;
use App\Models\StaffIncomeTaxSetting;
use App\Models\StaffStoreAssignment;
use App\Models\StaffStoreTransportationFee;
use App\Models\StaffWageRate;
use App\Models\Store;
use App\Models\StoreHoliday;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_data_can_be_registered_and_updated_through_management_routes(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), [
                'name' => '新店舗',
                'is_active' => true,
            ])
            ->assertRedirect();
        $store = Store::query()->where('name', '新店舗')->firstOrFail();

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
                'name' => '社員 太郎',
                'employment_type' => EmploymentType::Employee->value,
                'hired_at' => '2026-04-01',
                'retired_at' => null,
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
                'name' => 'アルバイト 花子',
                'employment_type' => EmploymentType::PartTime->value,
                'hired_at' => null,
                'retired_at' => null,
            ])
            ->assertRedirect();
        $partTime = Staff::query()->where('name', 'アルバイト 花子')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('staffs.assignments.store', $partTime), [
                'store_id' => $store->id,
                'effective_from' => '2026-04-01',
                'effective_to' => null,
            ])
            ->assertRedirect();
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
}
