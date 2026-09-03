<?php

namespace App\Services;

use App\Enums\ShiftType;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ShiftCalendarService
{
    /** @return array{days: list<array<string, mixed>>, staffs: list<array<string, mixed>>} */
    public function monthly(Store $store, Carbon $month): array
    {
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();
        $holidays = $store->holidays()
            ->whereBetween('holiday_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->pluck('holiday_date')
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
            ->flip();

        $staffs = Staff::query()
            ->where(function (Builder $query) use ($store, $periodStart, $periodEnd): void {
                $query
                    ->whereHas('storeAssignments', fn ($query) => $query
                        ->where('store_id', $store->id)
                        ->whereDate('effective_from', '<=', $periodEnd->toDateString())
                        ->where(fn ($query) => $query
                            ->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', $periodStart->toDateString())))
                    ->orWhereHas('shifts', fn ($query) => $query
                        ->where('store_id', $store->id)
                        ->whereBetween('shift_date', [
                            $periodStart->toDateString(),
                            $periodEnd->toDateString(),
                        ]));
            })
            ->with([
                'storeAssignments' => fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->whereDate('effective_from', '<=', $periodEnd->toDateString())
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $periodStart->toDateString())),
                'shifts' => fn ($query) => $query
                    ->whereBetween('shift_date', [
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    ])
                    ->with('store:id,name'),
            ])
            ->orderBy('name')
            ->get();

        $days = array_values(collect(range(1, $periodEnd->day))
            ->map(function (int $day) use ($periodStart, $holidays): array {
                $date = $periodStart->copy()->day($day);

                return [
                    'date' => $date->toDateString(),
                    'day' => $day,
                    'weekday' => $this->weekday($date),
                    'is_saturday' => $date->isSaturday(),
                    'is_sunday' => $date->isSunday(),
                    'is_holiday' => $holidays->has($date->toDateString()),
                ];
            })
            ->values()
            ->all());

        return [
            'days' => $days,
            'staffs' => array_values($staffs
                ->map(function (Staff $staff) use ($days, $holidays, $store): array {
                    /** @var Collection<string, Shift> $shifts */
                    $shifts = $staff->shifts->keyBy(
                        fn (Shift $shift): string => $shift->shift_date->toDateString(),
                    );

                    return [
                        'id' => $staff->id,
                        'name' => $staff->name,
                        'employment_type' => $staff->employment_type->value,
                        'cells' => collect($days)
                            ->map(function (array $day) use ($staff, $shifts, $holidays, $store): array {
                                $date = $day['date'];
                                $shift = $shifts->get($date);
                                $assigned = $staff->storeAssignments->contains(
                                    fn ($assignment): bool => $assignment->isEffectiveOn($date),
                                );
                                $eligible = $staff->isEmployedOn($date) && $assigned;
                                $conflict = $shift !== null
                                    && $shift->shift_type !== ShiftType::Off
                                    && $shift->store_id !== $store->id;
                                $inconsistency = $this->inconsistency(
                                    $shift,
                                    $store,
                                    $staff->isEmployedOn($date),
                                    $assigned,
                                    $holidays->has($date),
                                );

                                return [
                                    'date' => $date,
                                    'eligible' => $eligible,
                                    'editable' => $store->is_active
                                        && ! $holidays->has($date)
                                        && $eligible
                                        && ! $conflict,
                                    'conflict_store' => $conflict ? $shift->store?->name : null,
                                    'inconsistency' => $inconsistency,
                                    ...$this->shiftPayload($shift, $store),
                                ];
                            })
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all()),
        ];
    }

    /** @return array{is_holiday: bool, staffs: list<array<string, mixed>>} */
    public function daily(Store $store, Carbon $date): array
    {
        $dateString = $date->toDateString();
        $isHoliday = $store->holidays()->whereDate('holiday_date', $dateString)->exists();

        $candidateIds = Staff::query()
            ->where(function (Builder $query) use ($dateString): void {
                $query
                    ->whereNull('hired_at')
                    ->orWhereDate('hired_at', '<=', $dateString);
            })
            ->where(function (Builder $query) use ($dateString): void {
                $query
                    ->whereNull('retired_at')
                    ->orWhereDate('retired_at', '>=', $dateString);
            })
            ->whereHas('storeAssignments', fn ($query) => $query
                ->where('store_id', $store->id)
                ->whereDate('effective_from', '<=', $dateString)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $dateString)))
            ->pluck('id');

        $existingIds = Shift::query()
            ->where('store_id', $store->id)
            ->whereDate('shift_date', $dateString)
            ->pluck('staff_id');

        $staffs = Staff::query()
            ->whereKey($candidateIds->merge($existingIds)->unique())
            ->with([
                'storeAssignments' => fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->whereDate('effective_from', '<=', $dateString)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $dateString)),
                'shifts' => fn ($query) => $query
                    ->whereDate('shift_date', $dateString)
                    ->with('store:id,name'),
            ])
            ->orderBy('name')
            ->get();

        return [
            'is_holiday' => $isHoliday,
            'staffs' => array_values($staffs
                ->map(function (Staff $staff) use ($dateString, $isHoliday, $store): array {
                    $shift = $staff->shifts->first();
                    $eligible = $staff->isEmployedOn($dateString)
                        && $staff->storeAssignments->isNotEmpty();
                    $conflict = $shift !== null
                        && $shift->shift_type !== ShiftType::Off
                        && $shift->store_id !== $store->id;
                    $inconsistency = $this->inconsistency(
                        $shift,
                        $store,
                        $staff->isEmployedOn($dateString),
                        $staff->storeAssignments->isNotEmpty(),
                        $isHoliday,
                    );

                    return [
                        'id' => $staff->id,
                        'name' => $staff->name,
                        'employment_type' => $staff->employment_type->value,
                        'employment_type_label' => $staff->employment_type->label(),
                        'eligible' => $eligible,
                        'editable' => $store->is_active
                            && ! $isHoliday
                            && $eligible
                            && ! $conflict,
                        'conflict_store' => $conflict ? $shift->store?->name : null,
                        'inconsistency' => $inconsistency,
                        ...$this->shiftPayload($shift, $store),
                    ];
                })
                ->values()
                ->all()),
        ];
    }

    /** @return array{shift_type: string|null, start_time: string|null, display: string} */
    private function shiftPayload(?Shift $shift, Store $store): array
    {
        if ($shift === null) {
            return ['shift_type' => null, 'start_time' => null, 'display' => ''];
        }

        if ($shift->shift_type === ShiftType::Off) {
            return ['shift_type' => 'off', 'start_time' => null, 'display' => '休'];
        }

        if ($shift->store_id !== $store->id) {
            return ['shift_type' => null, 'start_time' => null, 'display' => '他店'];
        }

        if ($shift->shift_type === ShiftType::Early) {
            return ['shift_type' => 'early', 'start_time' => null, 'display' => '早'];
        }

        $startTime = $shift->start_time === null
            ? null
            : substr($shift->start_time, 0, 5);

        return [
            'shift_type' => 'time',
            'start_time' => $startTime,
            'display' => $startTime === null ? '' : substr($startTime, 0, 2),
        ];
    }

    private function weekday(Carbon $date): string
    {
        return ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek];
    }

    private function inconsistency(
        ?Shift $shift,
        Store $store,
        bool $employed,
        bool $assigned,
        bool $holiday,
    ): ?string {
        if ($shift === null) {
            return null;
        }

        if (! $employed) {
            return '在籍期間外の既存シフトです。';
        }

        if ($shift->shift_type === ShiftType::Off) {
            return null;
        }

        if ($shift->store_id !== $store->id) {
            return null;
        }

        if ($holiday) {
            return '店休日に勤務シフトが残っています。';
        }

        if (! $assigned) {
            return '店舗所属期間外の既存シフトです。';
        }

        return null;
    }
}
