<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Enums\TransportationTaxType;
use App\Models\AttendanceRecord;
use App\Models\Commission;
use App\Models\LateNightRateSetting;
use App\Models\Payroll;
use App\Models\Staff;
use App\Models\StaffIncomeTaxSetting;
use App\Models\StaffStoreTransportationFee;
use App\Models\StaffWageRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCalculationService
{
    public function __construct(private IncomeTaxCalculationService $incomeTax) {}

    public function calculate(Staff $staff, int $year, int $month): Payroll
    {
        return DB::transaction(fn (): Payroll => $this->calculateLocked($staff, $year, $month));
    }

    public function calculateAll(int $year, int $month): void
    {
        $this->validatePeriod($year, $month);

        DB::transaction(function () use ($year, $month): void {
            [$start, $end] = $this->period($year, $month);
            $staffs = Staff::query()
                ->where('employment_type', EmploymentType::PartTime->value)
                ->where(fn (Builder $query) => $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $end))
                ->where(fn (Builder $query) => $query->whereNull('retired_at')->orWhereDate('retired_at', '>=', $start))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($staffs as $staff) {
                $this->calculateLocked($staff, $year, $month, false);
            }
        });
    }

    private function calculateLocked(Staff $staff, int $year, int $month, bool $lockStaff = true): Payroll
    {
        $this->validatePeriod($year, $month);
        if ($lockStaff) {
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
        }
        if ($staff->employment_type !== EmploymentType::PartTime) {
            throw ValidationException::withMessages(['payroll' => '社員は給与計算の対象外です。']);
        }

        [$start, $end] = $this->period($year, $month);
        if (($staff->hired_at !== null && $staff->hired_at->greaterThan($end))
            || ($staff->retired_at !== null && $staff->retired_at->lessThan($start))) {
            throw ValidationException::withMessages(['payroll' => '対象月に在籍していないスタッフです。']);
        }
        $paymentDate = $start->copy()->addMonth()->day(10);
        $attendances = AttendanceRecord::query()
            ->where('staff_id', $staff->id)
            ->whereBetween('work_date', [$start, $end])
            ->orderBy('work_date')
            ->lockForUpdate()
            ->get();
        $wages = StaffWageRate::query()
            ->where('staff_id', $staff->id)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start))
            ->get();
        $lateRates = LateNightRateSetting::query()
            ->whereDate('effective_from', '<=', $end)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start))
            ->get();
        $transportationFees = StaffStoreTransportationFee::query()
            ->where('staff_id', $staff->id)
            ->whereIn('store_id', $attendances->pluck('store_id')->unique())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start))
            ->get();
        $rawBasePay = 0;
        $rawLateNightPay = 0;
        $transportationTaxable = 0;
        $transportationNonTaxable = 0;

        foreach ($attendances as $attendance) {
            $date = $attendance->work_date->toDateString();
            /** @var StaffWageRate $wage */
            $wage = $this->effectiveSetting(
                $wages,
                $date,
                "{$staff->name}さんの{$date}の時給が一意に設定されていません。",
                false,
            );
            /** @var LateNightRateSetting|null $lateRate */
            $lateRate = $this->effectiveSetting(
                $lateRates,
                $date,
                "{$staff->name}さんの{$date}の深夜加算額が複数設定されています。",
                true,
            );
            /** @var StaffStoreTransportationFee|null $transportation */
            $transportation = $this->effectiveSetting(
                $transportationFees->where('store_id', $attendance->store_id),
                $date,
                "{$staff->name}さんの{$date}の勤務店舗に対する交通費が複数設定されています。",
                true,
            );

            $rawBasePay += $attendance->working_minutes * $wage->hourly_wage;
            $lateAmountPerHour = $lateRate instanceof LateNightRateSetting
                ? $lateRate->amount_per_hour
                : 0;
            $rawLateNightPay += $attendance->late_night_minutes * $lateAmountPerHour;
            if ($transportation === null) {
                continue;
            }
            if ($transportation->tax_type === TransportationTaxType::Taxable) {
                $transportationTaxable += $transportation->amount_per_day;
            } else {
                $transportationNonTaxable += $transportation->amount_per_day;
            }
        }

        /** @var StaffIncomeTaxSetting $taxSetting */
        $taxSetting = $this->oneEffectiveQuery(
            $staff->incomeTaxSettings()->getQuery(),
            $paymentDate->toDateString(),
            "{$staff->name}さんの支給日{$paymentDate->format('Y/m/d')}に有効な所得税設定が一意に登録されていません。",
        );
        $commission = (int) Commission::query()
            ->where('staff_id', $staff->id)
            ->where('year', $year)
            ->where('month', $month)
            ->value('amount');
        $basePay = $this->ceilSixtieth($rawBasePay);
        $lateNightPay = $this->ceilSixtieth($rawLateNightPay);
        $transportationTotal = $transportationTaxable + $transportationNonTaxable;
        $grossPay = $basePay + $lateNightPay + $transportationTotal + $commission;
        $taxablePay = $basePay + $lateNightPay + $commission + $transportationTaxable;
        $socialInsuranceDeduction = 0;
        $referenceAmount = max(0, $taxablePay - $socialInsuranceDeduction);
        $incomeTax = $this->incomeTax->calculate(
            $paymentDate->year,
            $taxSetting->tax_category,
            $taxSetting->dependent_count,
            $referenceAmount,
        );
        $otherDeductions = 0;
        $totalDeductions = $incomeTax + $otherDeductions;

        return Payroll::query()->updateOrCreate(
            ['staff_id' => $staff->id, 'year' => $year, 'month' => $month],
            [
                'payment_date' => $paymentDate,
                'tax_year' => $paymentDate->year,
                'working_minutes' => $attendances->sum('working_minutes'),
                'late_night_minutes' => $attendances->sum('late_night_minutes'),
                'base_pay' => $basePay,
                'late_night_pay' => $lateNightPay,
                'transportation_fee_total' => $transportationTotal,
                'transportation_fee_taxable' => $transportationTaxable,
                'transportation_fee_non_taxable' => $transportationNonTaxable,
                'commission' => $commission,
                'gross_pay' => $grossPay,
                'taxable_pay' => $taxablePay,
                'social_insurance_deduction' => $socialInsuranceDeduction,
                'tax_table_reference_amount' => $referenceAmount,
                'income_tax' => $incomeTax,
                'other_deductions' => $otherDeductions,
                'total_deductions' => $totalDeductions,
                'net_pay' => $grossPay - $totalDeductions,
                'needs_recalculation' => false,
                'calculated_at' => now(),
            ],
        );
    }

    /** @return array{Carbon, Carbon} */
    private function period(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    private function validatePeriod(int $year, int $month): void
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period' => '給与対象年月が不正です。']);
        }
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function oneEffectiveQuery(Builder $query, string $date, string $message): Model
    {
        $settings = $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            ->limit(2)
            ->get();
        if ($settings->count() !== 1) {
            throw ValidationException::withMessages(['payroll' => $message]);
        }

        /** @var TModel $setting */
        $setting = $settings->firstOrFail();

        return $setting;
    }

    /**
     * @template TSetting of StaffWageRate|LateNightRateSetting|StaffStoreTransportationFee
     *
     * @param  Collection<int, TSetting>  $settings
     * @return TSetting|null
     */
    private function effectiveSetting(
        Collection $settings,
        string $date,
        string $message,
        bool $optional,
    ): StaffWageRate|LateNightRateSetting|StaffStoreTransportationFee|null {
        $effective = $settings
            ->filter(fn ($setting): bool => $setting->isEffectiveOn($date))
            ->values();
        if ($effective->count() > 1 || (! $optional && $effective->count() !== 1)) {
            throw ValidationException::withMessages(['payroll' => $message]);
        }

        /** @var TSetting|null $setting */
        $setting = $effective->first();

        return $setting;
    }

    private function ceilSixtieth(int $minuteRateTotal): int
    {
        return intdiv($minuteRateTotal + 59, 60);
    }
}
