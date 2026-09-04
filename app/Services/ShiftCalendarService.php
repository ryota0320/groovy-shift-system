<?php

namespace App\Services;

use App\Enums\ShiftType;
use App\Models\MonthlyShiftStaffAddition;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ShiftCalendarService
{
    /** @return array{days: list<array<string, mixed>>, staffs: list<array<string, mixed>>, addable_staffs: list<array<string, mixed>>} */
    public function monthly(Store $store, Carbon $month): array
    {
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();
        $holidays = $store->holidays()
            ->whereBetween('holiday_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->pluck('holiday_date')
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
            ->flip();
        $activeStores = Store::query()
            ->where('is_active', true)
            ->with(['holidays' => fn ($query) => $query->whereBetween('holiday_date', [
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
            ])])
            ->get()
            ->keyBy('id');
        $additions = MonthlyShiftStaffAddition::query()
            ->where('store_id', $store->id)
            ->whereDate('month', $periodStart->toDateString())
            ->orderBy('position')
            ->orderBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        $staffs = Staff::query()
            ->where(function (Builder $query) use ($additions, $store, $periodStart, $periodEnd): void {
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
                        ]))
                    ->orWhereIn('staffs.id', $additions->keys());
            })
            ->with([
                'storeAssignments' => fn ($query) => $query
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
            ->inDisplayOrder($store->id)
            ->get();
        $addedStaffIds = $additions->keys()
            ->filter(function (int $staffId) use ($staffs, $store): bool {
                $staff = $staffs->firstWhere('id', $staffId);

                return $staff instanceof Staff && ! $staff->storeAssignments->contains(
                    fn ($assignment): bool => $assignment->store_id === $store->id,
                );
            });
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
                ->map(function (Staff $staff) use ($activeStores, $addedStaffIds, $days, $holidays, $store): array {
                    /** @var Collection<string, Shift> $shifts */
                    $shifts = $staff->shifts->keyBy(
                        fn (Shift $shift): string => $shift->shift_date->toDateString(),
                    );
                    $assignedToContextStore = $staff->storeAssignments->contains(
                        fn ($assignment): bool => $assignment->store_id === $store->id,
                    );

                    return [
                        'id' => $staff->id,
                        'name' => $staff->preferred_name,
                        'employment_type' => $staff->employment_type->value,
                        'is_added' => $addedStaffIds->contains($staff->id),
                        'can_remove' => ! $assignedToContextStore,
                        'cells' => collect($days)
                            ->map(function (array $day) use ($activeStores, $addedStaffIds, $staff, $shifts, $holidays, $store): array {
                                $date = $day['date'];
                                $shift = $shifts->get($date);
                                $assigned = $staff->storeAssignments->contains(
                                    fn ($assignment): bool => $assignment->store_id === $store->id
                                        && $assignment->isEffectiveOn($date),
                                );
                                $assignedToAnyActiveStore = $staff->storeAssignments->contains(
                                    fn ($assignment): bool => $activeStores->has($assignment->store_id)
                                        && $assignment->isEffectiveOn($date),
                                );
                                $eligible = $staff->isEmployedOn($date)
                                    && ($assigned
                                        || ($addedStaffIds->contains($staff->id) && $assignedToAnyActiveStore)
                                        || ($shift?->shift_type !== ShiftType::Off
                                        && $shift?->store_id === $store->id));
                                $availableStoreIds = $this->availableStoreIds(
                                    $date,
                                    $activeStores,
                                );
                                $inconsistency = $this->inconsistency(
                                    $shift,
                                    $staff->isEmployedOn($date),
                                    $date,
                                    $activeStores,
                                );
                                $conflictStore = $addedStaffIds->contains($staff->id)
                                    && $shift !== null
                                    && (in_array($shift->shift_type, [ShiftType::Off, ShiftType::Absence], true)
                                        || $shift->store_id !== $store->id)
                                    ? match ($shift->shift_type) {
                                        ShiftType::Off => '休み',
                                        ShiftType::Absence => '急な休み',
                                        default => $shift->store?->name,
                                    }
                                : null;

                                return [
                                    'date' => $date,
                                    'eligible' => $eligible,
                                    'editable' => $store->is_active
                                        && $eligible
                                        && $inconsistency === null
                                        && $conflictStore === null
                                        && $this->isEditableForContextHoliday(
                                            $holidays->has($date),
                                            $shift,
                                            $store,
                                            $availableStoreIds,
                                        ),
                                    'available_store_ids' => $availableStoreIds,
                                    'conflict_store' => $conflictStore,
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
            'addable_staffs' => $this->addableMonthlyStaffs(
                $periodStart,
                $periodEnd,
                $staffs->pluck('id'),
            ),
        ];
    }

    /**
     * @param  Collection<int, int>  $excludedStaffIds
     * @return list<array{id: int, name: string, employment_type: string, employment_type_label: string, assignment_store_names: list<string>}>
     */
    private function addableMonthlyStaffs(Carbon $periodStart, Carbon $periodEnd, Collection $excludedStaffIds): array
    {
        return array_values(Staff::query()
            ->whereNotIn('staffs.id', $excludedStaffIds)
            ->where(function (Builder $query) use ($periodEnd): void {
                $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $periodEnd->toDateString());
            })
            ->where(function (Builder $query) use ($periodStart): void {
                $query->whereNull('retired_at')->orWhereDate('retired_at', '>=', $periodStart->toDateString());
            })
            ->whereHas('storeAssignments', fn ($query) => $query
                ->whereDate('effective_from', '<=', $periodEnd->toDateString())
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodStart->toDateString()))
                ->whereHas('store', fn ($query) => $query->where('is_active', true)))
            ->with(['storeAssignments' => fn ($query) => $query
                ->whereDate('effective_from', '<=', $periodEnd->toDateString())
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodStart->toDateString()))
                ->whereHas('store', fn ($query) => $query->where('is_active', true))
                ->with('store:id,name')])
            ->inDisplayOrder()
            ->get()
            ->map(fn (Staff $staff): array => [
                'id' => $staff->id,
                'name' => $staff->preferred_name,
                'employment_type' => $staff->employment_type->value,
                'employment_type_label' => $staff->employment_type->label(),
                'assignment_store_names' => array_values($staff->storeAssignments
                    ->pluck('store.name')
                    ->filter()
                    ->map(fn (mixed $name): string => (string) $name)
                    ->unique()
                    ->values()
                    ->all()),
            ])
            ->values()
            ->all());
    }

    /** @return array{is_holiday: bool, staffs: list<array<string, mixed>>} */
    public function daily(Store $store, Carbon $date): array
    {
        $dateString = $date->toDateString();
        $isHoliday = $store->holidays()->whereDate('holiday_date', $dateString)->exists();
        $activeStores = Store::query()
            ->where('is_active', true)
            ->with(['holidays' => fn ($query) => $query->whereDate('holiday_date', $dateString)])
            ->get()
            ->keyBy('id');

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
                    ->whereDate('effective_from', '<=', $dateString)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $dateString)),
                'shifts' => fn ($query) => $query
                    ->whereDate('shift_date', $dateString)
                    ->with('store:id,name'),
            ])
            ->inDisplayOrder($store->id)
            ->get();

        return [
            'is_holiday' => $isHoliday,
            'staffs' => array_values($staffs
                ->map(function (Staff $staff) use ($activeStores, $dateString, $isHoliday, $store): array {
                    $shift = $staff->shifts->first();
                    $eligible = $staff->isEmployedOn($dateString)
                        && ($staff->storeAssignments->contains(
                            fn ($assignment): bool => $assignment->store_id === $store->id,
                        ) || ($shift?->shift_type !== ShiftType::Off && $shift?->store_id === $store->id));
                    $availableStoreIds = $this->availableStoreIds(
                        $dateString,
                        $activeStores,
                    );
                    $inconsistency = $this->inconsistency(
                        $shift,
                        $staff->isEmployedOn($dateString),
                        $dateString,
                        $activeStores,
                    );

                    return [
                        'id' => $staff->id,
                        'name' => $staff->preferred_name,
                        'employment_type' => $staff->employment_type->value,
                        'employment_type_label' => $staff->employment_type->label(),
                        'eligible' => $eligible,
                        'editable' => $store->is_active
                            && $eligible
                            && $inconsistency === null
                            && $this->isEditableForContextHoliday(
                                $isHoliday,
                                $shift,
                                $store,
                                $availableStoreIds,
                            ),
                        'available_store_ids' => $availableStoreIds,
                        'conflict_store' => null,
                        'inconsistency' => $inconsistency,
                        ...$this->shiftPayload($shift, $store),
                    ];
                })
                ->values()
                ->all()),
            'addable_staffs' => $this->addableDailyStaffs(
                $dateString,
                $staffs->pluck('id'),
                $activeStores,
            ),
        ];
    }

    /**
     * @param  Collection<int, int>  $excludedStaffIds
     * @param  Collection<int, Store>  $activeStores
     * @return list<array{id: int, name: string, employment_type: string, employment_type_label: string, assignment_store_names: list<string>, available_store_ids: list<int>}>
     */
    private function addableDailyStaffs(
        string $date,
        Collection $excludedStaffIds,
        Collection $activeStores,
    ): array {
        return array_values(Staff::query()
            ->whereNotIn('id', $excludedStaffIds)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $date);
            })
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('retired_at')->orWhereDate('retired_at', '>=', $date);
            })
            ->whereHas('storeAssignments', fn ($query) => $query
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date))
                ->whereHas('store', fn ($query) => $query->where('is_active', true)))
            ->whereDoesntHave('shifts', fn ($query) => $query->whereDate('shift_date', $date))
            ->with(['storeAssignments' => fn ($query) => $query
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date))
                ->whereHas('store', fn ($query) => $query->where('is_active', true))
                ->with('store:id,name')])
            ->inDisplayOrder()
            ->get()
            ->map(fn (Staff $staff): array => [
                'id' => $staff->id,
                'name' => $staff->preferred_name,
                'employment_type' => $staff->employment_type->value,
                'employment_type_label' => $staff->employment_type->label(),
                'assignment_store_names' => array_values($staff->storeAssignments
                    ->pluck('store.name')
                    ->filter()
                    ->map(fn (mixed $name): string => (string) $name)
                    ->values()
                    ->all()),
                'available_store_ids' => $this->availableStoreIds($date, $activeStores),
            ])
            ->values()
            ->all());
    }

    /** @return array{shift_type: string|null, start_time: string|null, store_id: int|null, display: string} */
    private function shiftPayload(?Shift $shift, Store $store): array
    {
        if ($shift === null) {
            return ['shift_type' => null, 'start_time' => null, 'store_id' => null, 'display' => ''];
        }

        if ($shift->shift_type === ShiftType::Off) {
            return ['shift_type' => 'off', 'start_time' => null, 'store_id' => null, 'display' => '休'];
        }

        if ($shift->shift_type === ShiftType::Absence) {
            return ['shift_type' => 'absence', 'start_time' => null, 'store_id' => null, 'display' => '急休'];
        }

        if ($shift->store_id !== $store->id) {
            return [
                'shift_type' => 'help',
                'start_time' => null,
                'store_id' => $shift->store_id,
                'display' => $shift->store->name,
            ];
        }

        if ($shift->shift_type === ShiftType::Early) {
            return [
                'shift_type' => 'early',
                'start_time' => null,
                'store_id' => $shift->store_id,
                'display' => '早',
            ];
        }

        if ($shift->shift_type === ShiftType::Help) {
            return [
                'shift_type' => 'help',
                'start_time' => null,
                'store_id' => $shift->store_id,
                'display' => $shift->store->name,
            ];
        }

        $startTime = $shift->start_time === null
            ? null
            : substr($shift->start_time, 0, 5);

        return [
            'shift_type' => 'time',
            'start_time' => $startTime,
            'store_id' => $shift->store_id,
            'display' => $startTime === null
                ? ''
                : ($startTime === '00:00' ? '24' : substr($startTime, 0, 2)),
        ];
    }

    private function weekday(Carbon $date): string
    {
        return ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek];
    }

    /** @param Collection<int, Store> $activeStores */
    private function inconsistency(
        ?Shift $shift,
        bool $employed,
        string $date,
        Collection $activeStores,
    ): ?string {
        if ($shift === null) {
            return null;
        }

        if (! $employed) {
            return '在籍期間外の既存シフトです。';
        }

        if (in_array($shift->shift_type, [ShiftType::Off, ShiftType::Absence], true)) {
            return null;
        }

        if ($shift->store_id === null) {
            return '勤務店舗がない既存シフトです。';
        }

        $workStore = $activeStores->get($shift->store_id);
        if (! $workStore instanceof Store) {
            return '無効な店舗の既存シフトです。';
        }

        if ($this->isHoliday($workStore, $date)) {
            return '店休日に勤務シフトが残っています。';
        }

        return null;
    }

    /**
     * @param  Collection<int, Store>  $activeStores
     * @return list<int>
     */
    private function availableStoreIds(string $date, Collection $activeStores): array
    {
        return array_values($activeStores
            ->filter(fn (Store $store): bool => ! $this->isHoliday($store, $date))
            ->keys()
            ->map(fn (mixed $storeId): int => (int) $storeId)
            ->values()
            ->all());
    }

    private function isHoliday(Store $store, string $date): bool
    {
        return $store->holidays->contains(
            fn ($holiday): bool => $holiday->holiday_date->toDateString() === $date,
        );
    }

    /** @param list<int> $availableStoreIds */
    private function isEditableForContextHoliday(
        bool $contextHoliday,
        ?Shift $shift,
        Store $contextStore,
        array $availableStoreIds,
    ): bool {
        if (! $contextHoliday) {
            return true;
        }

        if ($availableStoreIds === []) {
            return false;
        }

        if ($shift === null) {
            return true;
        }

        return in_array($shift->shift_type, [ShiftType::Time, ShiftType::Early, ShiftType::Help], true)
            && $shift->store_id !== $contextStore->id
            && in_array($shift->store_id, $availableStoreIds, true);
    }
}
