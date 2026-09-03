<?php

namespace Tests\Feature;

use App\Enums\IncomeTaxCategory;
use App\Enums\TransportationTaxType;
use App\Models\AttendanceRecord;
use App\Models\Commission;
use App\Models\IncomeTaxRule;
use App\Models\IncomeTaxTableVersion;
use App\Models\LateNightRateSetting;
use App\Models\Payroll;
use App\Models\Staff;
use App\Models\StaffIncomeTaxSetting;
use App\Models\StaffStoreTransportationFee;
use App\Models\StaffWageRate;
use App\Models\Store;
use App\Models\StoreHoliday;
use App\Models\User;
use App\Services\IncomeTaxCalculationService;
use App\Services\PayrollCalculationService;
use Database\Seeders\IncomeTaxTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Covers PAY-001 through PAY-016 and TAX-001 through TAX-015. */
class PayrollManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_income_tax_tables_cover_boundaries_dependents_and_formulas(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);

        $this->assertSame(2, IncomeTaxTableVersion::query()->count());
        $this->assertSame(2162, IncomeTaxRule::query()
            ->whereRelation('tableVersion', 'tax_year', 2026)->count());
        $this->assertSame(2135, IncomeTaxRule::query()
            ->whereRelation('tableVersion', 'tax_year', 2027)->count());
        $this->assertDatabaseHas('income_tax_table_versions', [
            'tax_year' => 2026,
            'source_hash' => '50aafa072df1bb6b6aa253a021f7cc246265c3f2393f9988ee01ad121bc4f310',
        ]);
        $this->assertDatabaseHas('income_tax_table_versions', [
            'tax_year' => 2027,
            'source_hash' => 'f2f331de1207ae0da6a3f416c7ad233de9411b0210a65e928c39527f1791fea5',
        ]);

        $tax = app(IncomeTaxCalculationService::class);
        $cases = [
            [2026, IncomeTaxCategory::Ko, 0, 104999, 0],
            [2026, IncomeTaxCategory::Ko, 0, 105000, 170],
            [2026, IncomeTaxCategory::Ko, 1, 137000, 190],
            [2026, IncomeTaxCategory::Ko, 0, 739999, 71380],
            [2026, IncomeTaxCategory::Ko, 0, 740000, 71680],
            [2026, IncomeTaxCategory::Ko, 0, 790000, 81890],
            [2026, IncomeTaxCategory::Ko, 0, 960000, 121820],
            [2026, IncomeTaxCategory::Ko, 0, 1710000, 374520],
            [2026, IncomeTaxCategory::Ko, 0, 3500000, 1125270],
            [2026, IncomeTaxCategory::Ko, 7, 740000, 26410],
            [2026, IncomeTaxCategory::Ko, 8, 740000, 24800],
            [2026, IncomeTaxCategory::Otsu, 5, 100000, 3063],
            [2026, IncomeTaxCategory::Otsu, 0, 105000, 3800],
            [2026, IncomeTaxCategory::Otsu, 0, 739999, 257700],
            [2026, IncomeTaxCategory::Otsu, 0, 740000, 259200],
            [2026, IncomeTaxCategory::Otsu, 0, 1710000, 655400],
            [2026, IncomeTaxCategory::Otsu, 0, 3500000, 1477815],
            [2027, IncomeTaxCategory::Ko, 0, 110999, 0],
            [2027, IncomeTaxCategory::Ko, 0, 111000, 140],
            [2027, IncomeTaxCategory::Ko, 0, 740000, 71000],
            [2027, IncomeTaxCategory::Otsu, 0, 110999, 3399],
            [2027, IncomeTaxCategory::Otsu, 7, 111000, 4000],
            [2027, IncomeTaxCategory::Otsu, 0, 1720000, 659300],
        ];

        foreach ($cases as [$year, $category, $dependents, $amount, $expected]) {
            $this->assertSame(
                $expected,
                $tax->calculate($year, $category, $dependents, $amount),
                "{$year}/{$category->value}/{$dependents}/{$amount}",
            );
        }
    }

    public function test_income_tax_rules_are_contiguous_for_every_table_column(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);

        foreach (IncomeTaxTableVersion::query()->get() as $version) {
            foreach ([IncomeTaxCategory::Ko, IncomeTaxCategory::Otsu] as $category) {
                $dependents = $category === IncomeTaxCategory::Ko ? range(0, 7) : [null];
                foreach ($dependents as $dependent) {
                    $rules = $version->rules()
                        ->where('tax_category', $category->value)
                        ->when(
                            $dependent === null,
                            fn ($query) => $query->whereNull('dependent_count'),
                            fn ($query) => $query->where('dependent_count', $dependent),
                        )
                        ->orderBy('min_amount')
                        ->get();
                    $this->assertNotEmpty($rules);
                    $this->assertSame(0, $rules->firstOrFail()->min_amount);
                    foreach ($rules->zip($rules->slice(1)) as [$current, $next]) {
                        if ($next !== null) {
                            $this->assertSame($current->max_amount, $next->min_amount);
                        }
                    }
                    $this->assertNull($rules->last()?->max_amount);
                }
            }
        }
    }

    public function test_monthly_payroll_uses_all_stores_historical_rates_and_final_rounding(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $staff = Staff::factory()->partTime()->create([
            'name' => '給与 花子',
            'hired_at' => '2026-01-01',
        ]);
        $taxableStore = Store::factory()->create(['name' => '課税交通費店']);
        $nonTaxableStore = Store::factory()->create(['name' => '非課税交通費店']);
        $this->attendance($staff, $taxableStore, '2026-09-04', 15, 15);
        $this->attendance($staff, $nonTaxableStore, '2026-09-20', 15, 15);
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1261,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-14',
        ]);
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1263,
            'effective_from' => '2026-09-15',
            'effective_to' => null,
        ]);
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 151,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-14',
        ]);
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 153,
            'effective_from' => '2026-09-15',
            'effective_to' => null,
        ]);
        $this->transportation($staff, $taxableStore, 500, TransportationTaxType::Taxable);
        $this->transportation($staff, $nonTaxableStore, 600, TransportationTaxType::NonTaxable);
        $this->taxSetting($staff, IncomeTaxCategory::Ko, 0);
        Commission::query()->create([
            'staff_id' => $staff->id,
            'year' => 2026,
            'month' => 9,
            'amount' => 120000,
        ]);

        $payroll = app(PayrollCalculationService::class)->calculate($staff, 2026, 9);

        $this->assertSame('2026-10-10', $payroll->payment_date->toDateString());
        $this->assertSame(2026, $payroll->tax_year);
        $this->assertSame(30, $payroll->working_minutes);
        $this->assertSame(30, $payroll->late_night_minutes);
        $this->assertSame(631, $payroll->base_pay);
        $this->assertSame(76, $payroll->late_night_pay);
        $this->assertSame(1100, $payroll->transportation_fee_total);
        $this->assertSame(500, $payroll->transportation_fee_taxable);
        $this->assertSame(600, $payroll->transportation_fee_non_taxable);
        $this->assertSame(120000, $payroll->commission);
        $this->assertSame(121807, $payroll->gross_pay);
        $this->assertSame(121207, $payroll->taxable_pay);
        $this->assertSame(0, $payroll->social_insurance_deduction);
        $this->assertSame(121207, $payroll->tax_table_reference_amount);
        $this->assertSame(990, $payroll->income_tax);
        $this->assertSame(990, $payroll->total_deductions);
        $this->assertSame(120817, $payroll->net_pay);
        $this->assertFalse($payroll->needs_recalculation);

        $this->seed(IncomeTaxTableSeeder::class);
        $this->assertTrue($payroll->fresh()->needs_recalculation);
    }

    public function test_exact_base_and_late_night_rounding_also_include_store_holiday_work(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        $store = Store::factory()->create();
        StoreHoliday::query()->create(['store_id' => $store->id, 'holiday_date' => '2026-09-04']);
        AttendanceRecord::factory()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'work_date' => '2026-09-04',
            'clock_in_at' => '2026-09-04 20:00:00',
            'clock_out_at' => '2026-09-05 02:15:00',
            'working_minutes' => 375,
            'late_night_minutes' => 255,
        ]);
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1260,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 150,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->transportation($staff, $store, 500, TransportationTaxType::NonTaxable);
        $this->taxSetting($staff, IncomeTaxCategory::Ko, 0);

        $payroll = app(PayrollCalculationService::class)->calculate($staff, 2026, 9);

        $this->assertSame(7875, $payroll->base_pay);
        $this->assertSame(638, $payroll->late_night_pay);
        $this->assertSame(500, $payroll->transportation_fee_total);
    }

    public function test_unset_optional_additions_are_calculated_as_zero(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        $store = Store::factory()->create();
        $this->attendance($staff, $store, '2026-09-04', 60, 60);
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1260,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->taxSetting($staff, IncomeTaxCategory::Ko, 0);

        $payroll = app(PayrollCalculationService::class)->calculate($staff, 2026, 9);

        $this->assertSame(1260, $payroll->base_pay);
        $this->assertSame(0, $payroll->late_night_pay);
        $this->assertSame(0, $payroll->transportation_fee_total);
        $this->assertSame(0, $payroll->transportation_fee_taxable);
        $this->assertSame(0, $payroll->transportation_fee_non_taxable);
        $this->assertSame(0, $payroll->commission);
        $this->assertSame(1260, $payroll->gross_pay);
    }

    public function test_december_payroll_uses_next_year_table_and_payment_date_tax_setting(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        StaffIncomeTaxSetting::query()->create([
            'staff_id' => $staff->id,
            'tax_category' => IncomeTaxCategory::Ko,
            'dependent_count' => 7,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);
        $this->taxSetting($staff, IncomeTaxCategory::Ko, 0, '2027-01-01');
        Commission::query()->create([
            'staff_id' => $staff->id,
            'year' => 2026,
            'month' => 12,
            'amount' => 111000,
        ]);

        $january = app(PayrollCalculationService::class)->calculate($staff, 2026, 1);
        $this->assertSame('2026-02-10', $january->payment_date->toDateString());

        $payroll = app(PayrollCalculationService::class)->calculate($staff, 2026, 12);

        $this->assertSame('2027-01-10', $payroll->payment_date->toDateString());
        $this->assertSame(2027, $payroll->tax_year);
        $this->assertSame(140, $payroll->income_tax);
    }

    public function test_missing_payroll_settings_or_tax_table_fail_without_saving(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        $store = Store::factory()->create();
        $this->attendance($staff, $store, '2026-09-04', 60, 0);
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 150,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->transportation($staff, $store, 0, TransportationTaxType::NonTaxable);
        $this->taxSetting($staff, IncomeTaxCategory::Ko, 0);

        try {
            app(PayrollCalculationService::class)->calculate($staff, 2026, 9);
            $this->fail('時給未設定の給与計算が成功しました。');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('時給', $exception->getMessage());
        }
        $this->assertDatabaseCount('payrolls', 0);

        Commission::query()->create([
            'staff_id' => $staff->id,
            'year' => 2027,
            'month' => 12,
            'amount' => 100000,
        ]);
        try {
            app(PayrollCalculationService::class)->calculate($staff, 2027, 12);
            $this->fail('税額表未登録年の給与計算が成功しました。');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('2028年分', $exception->getMessage());
        }
        $this->assertDatabaseCount('payrolls', 0);
    }

    public function test_missing_payment_date_tax_setting_fails_without_saving(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        Commission::query()->create([
            'staff_id' => $staff->id,
            'year' => 2026,
            'month' => 9,
            'amount' => 100000,
        ]);

        try {
            app(PayrollCalculationService::class)->calculate($staff, 2026, 9);
            $this->fail('所得税設定未登録の給与計算が成功しました。');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('所得税設定', $exception->getMessage());
        }

        $this->assertDatabaseCount('payrolls', 0);
    }

    public function test_bulk_calculation_is_atomic_when_one_staff_fails(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $store = Store::factory()->create();
        $valid = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        $invalid = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 150,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        foreach ([$valid, $invalid] as $staff) {
            $this->attendance($staff, $store, '2026-09-04', 60, 0);
            $this->transportation($staff, $store, 0, TransportationTaxType::NonTaxable);
            $this->taxSetting($staff, IncomeTaxCategory::Ko, 0);
        }
        StaffWageRate::query()->create([
            'staff_id' => $valid->id,
            'hourly_wage' => 1260,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        try {
            app(PayrollCalculationService::class)->calculateAll(2026, 9);
            $this->fail('一部設定不足の一括給与計算が成功しました。');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('時給', $exception->getMessage());
        }

        $this->assertDatabaseCount('payrolls', 0);
    }

    public function test_payroll_screen_commission_and_recalculation_routes_exclude_employees(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $admin = User::factory()->create();
        $partTime = Staff::factory()->partTime()->create([
            'name' => '対象アルバイト',
            'hired_at' => '2026-01-01',
        ]);
        $employee = Staff::factory()->employee()->create([
            'name' => '対象外社員',
            'hired_at' => '2026-01-01',
        ]);
        $this->taxSetting($partTime, IncomeTaxCategory::Ko, 0);

        $this->actingAs($admin)
            ->get(route('payrolls.index', ['year' => 2026, 'month' => 9]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('payrolls/index')
                ->has('staffs', 1)
                ->where('staffs.0.name', '対象アルバイト')
                ->where('staffs.0.payroll', null));

        $employeeAccount = User::factory()->employee()->create();
        $this->actingAs($employeeAccount)
            ->get(route('payrolls.index', ['year' => 2026, 'month' => 9]))
            ->assertOk();

        $this->actingAs($admin)
            ->put(route('commissions.update'), [
                'staff_id' => $partTime->id,
                'year' => 2026,
                'month' => 9,
                'amount' => 120000,
            ])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post(route('payrolls.calculate', $partTime), ['year' => 2026, 'month' => 9])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payrolls', [
            'staff_id' => $partTime->id,
            'commission' => 120000,
            'needs_recalculation' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('commissions.update'), [
                'staff_id' => $partTime->id,
                'year' => 2026,
                'month' => 9,
                'amount' => 121000,
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Payroll::query()->firstOrFail()->needs_recalculation);

        $this->actingAs($admin)
            ->put(route('commissions.update'), [
                'staff_id' => $employee->id,
                'year' => 2026,
                'month' => 9,
                'amount' => 1000,
            ])
            ->assertSessionHasErrors('staff_id');
        $this->actingAs($admin)
            ->post(route('payrolls.calculate', $employee), ['year' => 2026, 'month' => 9])
            ->assertSessionHasErrors('payroll');
        $this->assertDatabaseMissing('payrolls', ['staff_id' => $employee->id]);

        $this->actingAs($admin)
            ->delete(route('commissions.destroy', [$partTime, 2026, 9]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('commissions', [
            'staff_id' => $partTime->id,
            'year' => 2026,
            'month' => 9,
        ]);
    }

    public function test_attendance_edit_marks_payroll_stale_and_individual_recalculation_refreshes_it(): void
    {
        $this->seed(IncomeTaxTableSeeder::class);
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        $store = Store::factory()->create();
        $staff->storeAssignments()->create([
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1261,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        LateNightRateSetting::query()->create([
            'amount_per_hour' => 150,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->transportation($staff, $store, 0, TransportationTaxType::NonTaxable);
        $this->taxSetting($staff, IncomeTaxCategory::Ko, 0);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), [
                'store_id' => $store->id,
                'work_date' => '2026-09-04',
                'confirmed_store_holiday' => false,
                'records' => [[
                    'staff_id' => $staff->id,
                    'clock_in_offset_minutes' => 1320,
                    'clock_out_offset_minutes' => 1335,
                ]],
            ])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post(route('payrolls.calculate', $staff), ['year' => 2026, 'month' => 9])
            ->assertSessionHasNoErrors();
        $payroll = Payroll::query()->firstOrFail();
        $this->assertSame(316, $payroll->base_pay);
        $this->assertFalse($payroll->needs_recalculation);

        $this->actingAs($admin)
            ->put(route('attendance.daily.save'), [
                'store_id' => $store->id,
                'work_date' => '2026-09-04',
                'confirmed_store_holiday' => false,
                'records' => [[
                    'staff_id' => $staff->id,
                    'clock_in_offset_minutes' => 1320,
                    'clock_out_offset_minutes' => 1350,
                ]],
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue($payroll->fresh()->needs_recalculation);

        $this->actingAs($admin)
            ->post(route('payrolls.calculate', $staff), ['year' => 2026, 'month' => 9])
            ->assertSessionHasNoErrors();
        $payroll->refresh();
        $this->assertSame(30, $payroll->working_minutes);
        $this->assertSame(631, $payroll->base_pay);
        $this->assertFalse($payroll->needs_recalculation);
    }

    public function test_rate_and_tax_setting_updates_mark_existing_payrolls_for_recalculation(): void
    {
        $admin = User::factory()->create();
        $staff = Staff::factory()->partTime()->create(['hired_at' => '2026-01-01']);
        $store = Store::factory()->create();
        $transportation = StaffStoreTransportationFee::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'amount_per_day' => 500,
            'tax_type' => TransportationTaxType::NonTaxable,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $lateNightRate = LateNightRateSetting::query()->create([
            'amount_per_hour' => 150,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $taxSetting = StaffIncomeTaxSetting::query()->create([
            'staff_id' => $staff->id,
            'tax_category' => IncomeTaxCategory::Ko,
            'dependent_count' => 0,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $payroll = Payroll::query()->create([
            'staff_id' => $staff->id,
            'year' => 2026,
            'month' => 9,
            'payment_date' => '2026-10-10',
            'tax_year' => 2026,
            'needs_recalculation' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('staffs.transportation-fees.update', [$staff, $transportation]), [
                'store_id' => $store->id,
                'amount_per_day' => 600,
                'tax_type' => TransportationTaxType::Taxable->value,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue($payroll->fresh()->needs_recalculation);

        $payroll->update(['needs_recalculation' => false]);
        $this->actingAs($admin)
            ->put(route('late-night-rates.update', $lateNightRate), [
                'amount_per_hour' => 200,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue($payroll->fresh()->needs_recalculation);

        $payroll->update(['needs_recalculation' => false]);
        $this->actingAs($admin)
            ->put(route('staffs.income-tax-settings.update', [$staff, $taxSetting]), [
                'tax_category' => IncomeTaxCategory::Otsu->value,
                'dependent_count' => 0,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue($payroll->fresh()->needs_recalculation);
    }

    private function attendance(
        Staff $staff,
        Store $store,
        string $date,
        int $workingMinutes,
        int $lateNightMinutes,
    ): AttendanceRecord {
        $clockIn = Carbon::parse("{$date} 22:00:00");

        return AttendanceRecord::factory()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'work_date' => $date,
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockIn->copy()->addMinutes($workingMinutes),
            'working_minutes' => $workingMinutes,
            'late_night_minutes' => $lateNightMinutes,
        ]);
    }

    private function transportation(
        Staff $staff,
        Store $store,
        int $amount,
        TransportationTaxType $taxType,
    ): void {
        StaffStoreTransportationFee::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'amount_per_day' => $amount,
            'tax_type' => $taxType,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    private function taxSetting(
        Staff $staff,
        IncomeTaxCategory $category,
        int $dependents,
        string $effectiveFrom = '2026-01-01',
    ): void {
        StaffIncomeTaxSetting::query()->create([
            'staff_id' => $staff->id,
            'tax_category' => $category,
            'dependent_count' => $dependents,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
        ]);
    }
}
