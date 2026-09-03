<?php

namespace App\Services;

use App\Data\MonthlyAggregationReport;
use App\Enums\EmploymentType;
use App\Models\AttendanceRecord;
use App\Models\LateNightRateSetting;
use App\Models\Payroll;
use App\Models\StaffStoreTransportationFee;
use App\Models\StaffWageRate;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MonthlyAggregationService
{
    public function build(int $year, int $month, ?Store $selectedStore): MonthlyAggregationReport
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $attendances = AttendanceRecord::query()
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->with(['staff:id,name,employment_type', 'store:id,name'])
            ->orderBy('work_date')
            ->orderBy('staff_id')
            ->get();
        $attendanceStoreIds = $attendances->pluck('store_id')->unique();
        $stores = Store::query()
            ->where(function ($query) use ($attendanceStoreIds, $selectedStore): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $attendanceStoreIds);

                if ($selectedStore !== null) {
                    $query->orWhereKey($selectedStore->id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
        $wages = StaffWageRate::query()
            ->whereIn('staff_id', $attendances->pluck('staff_id')->unique())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->get()
            ->groupBy('staff_id');
        $lateRates = LateNightRateSetting::query()
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->get();
        $transportationFees = StaffStoreTransportationFee::query()
            ->whereIn('staff_id', $attendances->pluck('staff_id')->unique())
            ->whereIn('store_id', $attendances->pluck('store_id')->unique())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->get()
            ->groupBy(fn (StaffStoreTransportationFee $fee): string => "{$fee->staff_id}:{$fee->store_id}");
        $costRows = $attendances->map(fn (AttendanceRecord $attendance): array => $this->costRow(
            $attendance,
            $wages->get($attendance->staff_id, collect()),
            $lateRates,
            $transportationFees->get("{$attendance->staff_id}:{$attendance->store_id}", collect()),
        ));
        $selectedRows = $selectedStore === null
            ? collect()
            : $costRows->where('store_id', $selectedStore->id)->values();

        return new MonthlyAggregationReport(
            year: $year,
            month: $month,
            storeId: $selectedStore?->id,
            stores: array_values($stores->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
            ])->all()),
            storeRows: $this->monthlyStoreRows($selectedRows),
            storeTotals: $this->totals($selectedRows),
            dailyGroups: $this->dailyGroups($selectedRows),
            crossStoreRows: $this->crossStoreRows($attendances, $stores, $year, $month),
        );
    }

    /**
     * @param  Collection<int, StaffWageRate>  $wages
     * @param  Collection<int, LateNightRateSetting>  $lateRates
     * @param  Collection<int, StaffStoreTransportationFee>  $transportationFees
     * @return array<string, mixed>
     */
    private function costRow(
        AttendanceRecord $attendance,
        Collection $wages,
        Collection $lateRates,
        Collection $transportationFees,
    ): array {
        $isPartTime = $attendance->staff->employment_type === EmploymentType::PartTime;
        $date = $attendance->work_date->toDateString();
        $rawBase = 0;
        $rawLate = 0;
        $transportation = 0;

        if ($isPartTime) {
            $wage = $this->oneEffective(
                $wages,
                $date,
                "{$attendance->staff->name}さんの{$date}の時給が一意に設定されていません。",
                false,
            );
            if (! $wage instanceof StaffWageRate) {
                throw ValidationException::withMessages([
                    'aggregation' => "{$attendance->staff->name}さんの{$date}の時給が設定されていません。",
                ]);
            }
            $lateRate = $this->oneEffective(
                $lateRates,
                $date,
                "{$attendance->staff->name}さんの{$date}の深夜加算額が複数設定されています。",
                true,
            );
            $fee = $this->oneEffective(
                $transportationFees,
                $date,
                "{$attendance->staff->name}さんの{$date}の交通費が複数設定されています。",
                true,
            );
            $rawBase = $attendance->working_minutes * $wage->hourly_wage;
            $rawLate = $attendance->late_night_minutes * ($lateRate instanceof LateNightRateSetting ? $lateRate->amount_per_hour : 0);
            $transportation = $fee instanceof StaffStoreTransportationFee ? $fee->amount_per_day : 0;
        }

        return [
            'staff_id' => $attendance->staff_id,
            'store_id' => $attendance->store_id,
            'work_date' => $date,
            'name' => $attendance->staff->name,
            'employment_type' => $attendance->staff->employment_type->value,
            'employment_type_label' => $attendance->staff->employment_type->label(),
            'working_minutes' => $attendance->working_minutes,
            'late_night_minutes' => $attendance->late_night_minutes,
            'raw_base_pay' => $rawBase,
            'raw_late_night_pay' => $rawLate,
            'transportation_fee' => $transportation,
            'base_pay' => $isPartTime ? $this->ceilSixtieth($rawBase) : null,
            'late_night_pay' => $isPartTime ? $this->ceilSixtieth($rawLate) : null,
            'labor_cost' => $isPartTime
                ? $this->ceilSixtieth($rawBase + $rawLate + ($transportation * 60))
                : null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function monthlyStoreRows(Collection $rows): array
    {
        return array_values($rows
            ->groupBy('staff_id')
            ->map(function (Collection $staffRows): array {
                $first = $staffRows->firstOrFail();
                $isPartTime = $first['employment_type'] === EmploymentType::PartTime->value;
                $rawBase = (int) $staffRows->sum('raw_base_pay');
                $rawLate = (int) $staffRows->sum('raw_late_night_pay');
                $transportation = (int) $staffRows->sum('transportation_fee');

                return [
                    'staff_id' => $first['staff_id'],
                    'name' => $first['name'],
                    'employment_type' => $first['employment_type'],
                    'employment_type_label' => $first['employment_type_label'],
                    'attendance_days' => $staffRows->pluck('work_date')->unique()->count(),
                    'working_minutes' => (int) $staffRows->sum('working_minutes'),
                    'late_night_minutes' => (int) $staffRows->sum('late_night_minutes'),
                    'base_pay' => $isPartTime ? $this->ceilSixtieth($rawBase) : null,
                    'late_night_pay' => $isPartTime ? $this->ceilSixtieth($rawLate) : null,
                    'transportation_fee' => $isPartTime ? $transportation : null,
                    'labor_cost' => $isPartTime
                        ? $this->ceilSixtieth($rawBase + $rawLate + ($transportation * 60))
                        : null,
                ];
            })
            ->sortBy(fn (array $row): string => sprintf(
                '%d-%010d',
                $row['employment_type'] === EmploymentType::Employee->value ? 0 : 1,
                $row['staff_id'],
            ))
            ->values()
            ->all());
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function totals(Collection $rows): array
    {
        $partTimeRows = $rows->where('employment_type', EmploymentType::PartTime->value);
        $rawBase = (int) $partTimeRows->sum('raw_base_pay');
        $rawLate = (int) $partTimeRows->sum('raw_late_night_pay');
        $transportation = (int) $partTimeRows->sum('transportation_fee');

        return [
            'attendance_days' => $rows->count(),
            'working_minutes' => (int) $rows->sum('working_minutes'),
            'late_night_minutes' => (int) $partTimeRows->sum('late_night_minutes'),
            'base_pay' => $this->ceilSixtieth($rawBase),
            'late_night_pay' => $this->ceilSixtieth($rawLate),
            'transportation_fee' => $transportation,
            'labor_cost' => $this->ceilSixtieth($rawBase + $rawLate + ($transportation * 60)),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return list<array{date: string, rows: list<array<string, mixed>>, totals: array<string, int>}>
     */
    private function dailyGroups(Collection $rows): array
    {
        return array_values($rows
            ->groupBy('work_date')
            ->map(fn (Collection $dailyRows, string $date): array => [
                'date' => $date,
                'rows' => array_values($dailyRows->map(fn (array $row): array => collect($row)
                    ->except(['raw_base_pay', 'raw_late_night_pay', 'store_id', 'work_date'])
                    ->all())->values()->all()),
                'totals' => $this->totals($dailyRows),
            ])
            ->sortKeys()
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $attendances
     * @param  Collection<int, Store>  $stores
     * @return list<array<string, mixed>>
     */
    private function crossStoreRows(Collection $attendances, Collection $stores, int $year, int $month): array
    {
        $payrolls = Payroll::query()
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('staff_id');

        return array_values($attendances
            ->groupBy('staff_id')
            ->map(function (Collection $staffAttendances) use ($stores, $payrolls): array {
                /** @var AttendanceRecord $first */
                $first = $staffAttendances->firstOrFail();
                $payroll = $payrolls->get($first->staff_id);

                return [
                    'staff_id' => $first->staff_id,
                    'name' => $first->staff->name,
                    'employment_type' => $first->staff->employment_type->value,
                    'employment_type_label' => $first->staff->employment_type->label(),
                    'attendance_days' => $staffAttendances->pluck('work_date')->unique()->count(),
                    'store_minutes' => array_values($stores->map(fn (Store $store): array => [
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'working_minutes' => (int) $staffAttendances->where('store_id', $store->id)->sum('working_minutes'),
                    ])->all()),
                    'working_minutes' => (int) $staffAttendances->sum('working_minutes'),
                    'late_night_minutes' => (int) $staffAttendances->sum('late_night_minutes'),
                    'payroll' => $payroll instanceof Payroll ? [
                        'base_pay' => $payroll->base_pay,
                        'late_night_pay' => $payroll->late_night_pay,
                        'transportation_fee' => $payroll->transportation_fee_total,
                        'gross_pay' => $payroll->gross_pay,
                        'needs_recalculation' => $payroll->needs_recalculation,
                    ] : null,
                ];
            })
            ->sortBy(fn (array $row): string => sprintf(
                '%d-%010d',
                $row['employment_type'] === EmploymentType::Employee->value ? 0 : 1,
                $row['staff_id'],
            ))
            ->values()
            ->all());
    }

    /**
     * @param  iterable<int, StaffWageRate|LateNightRateSetting|StaffStoreTransportationFee>  $settings
     */
    private function oneEffective(iterable $settings, string $date, string $message, bool $optional): StaffWageRate|LateNightRateSetting|StaffStoreTransportationFee|null
    {
        $effective = collect($settings)->filter(fn ($setting): bool => $setting->isEffectiveOn($date))->values();
        if ($effective->count() > 1 || (! $optional && $effective->count() !== 1)) {
            throw ValidationException::withMessages(['aggregation' => $message]);
        }

        return $effective->first();
    }

    private function ceilSixtieth(int $minuteRateTotal): int
    {
        return intdiv($minuteRateTotal + 59, 60);
    }
}
