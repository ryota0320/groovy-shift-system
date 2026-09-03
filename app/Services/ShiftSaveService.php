<?php

namespace App\Services;

use App\Enums\ShiftType;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftSaveService
{
    public function create(
        Store $contextStore,
        Staff $staff,
        string $shiftDate,
        ShiftType $shiftType,
        ?string $startTime,
        ?Store $workStore,
    ): Shift {
        return DB::transaction(function () use ($contextStore, $staff, $shiftDate, $shiftType, $startTime, $workStore): Shift {
            $stores = $this->lockStores($contextStore, $workStore);
            $contextStore = $stores->get($contextStore->id);
            $workStore = $workStore === null ? null : $stores->get($workStore->id);
            Staff::query()->lockForUpdate()->findOrFail($staff->id);

            if (Shift::query()
                ->where('staff_id', $staff->id)
                ->whereDate('shift_date', $shiftDate)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'shift_type' => 'このスタッフには同じ営業日のシフトが既に登録されています。',
                ]);
            }

            $this->validateContextStore($contextStore, $staff, $shiftDate, null, 'shift_type');
            $this->validateWorkShift($workStore, $staff, $shiftDate, $shiftType, $startTime, 'shift_type');

            return Shift::query()->create($this->attributes(
                $workStore,
                $staff,
                $shiftDate,
                $shiftType,
                $startTime,
            ));
        });
    }

    public function saveCell(
        Store $contextStore,
        Staff $staff,
        string $shiftDate,
        ?ShiftType $shiftType,
        ?string $startTime,
        ?Store $workStore,
    ): ?Shift {
        return DB::transaction(function () use ($contextStore, $staff, $shiftDate, $shiftType, $startTime, $workStore): ?Shift {
            $stores = $this->lockStores($contextStore, $workStore);
            $contextStore = $stores->get($contextStore->id);
            $workStore = $workStore === null ? null : $stores->get($workStore->id);
            Staff::query()->lockForUpdate()->findOrFail($staff->id);

            return $this->replace(
                $contextStore,
                $workStore,
                $staff,
                $shiftDate,
                $shiftType,
                $startTime,
                'shift_type',
            );
        });
    }

    /**
     * @param  list<array{staff_id: int, shift_type: string|null, start_time: string|null, work_store_id: int|null}>  $entries
     */
    public function saveDaily(Store $store, string $shiftDate, array $entries): void
    {
        DB::transaction(function () use ($store, $shiftDate, $entries): void {
            $storeIds = collect($entries)
                ->pluck('work_store_id')
                ->filter()
                ->push($store->id)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            $stores = Store::query()
                ->whereKey($storeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $contextStore = $stores->get($store->id);
            $staffIds = collect($entries)
                ->pluck('staff_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values();

            $staffs = Staff::query()
                ->whereKey($staffIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($entries as $index => $entry) {
                $staff = $staffs->get($entry['staff_id']);

                if (! $staff instanceof Staff) {
                    throw ValidationException::withMessages([
                        "shifts.{$index}.staff_id" => 'スタッフが見つかりません。',
                    ]);
                }

                $shiftType = $entry['shift_type'] === null
                    ? null
                    : ShiftType::from($entry['shift_type']);
                $workStoreId = $entry['work_store_id'] ?? null;
                $workStore = $workStoreId === null
                    ? null
                    : $stores->get($workStoreId);

                $this->replace(
                    $contextStore,
                    $workStore,
                    $staff,
                    $shiftDate,
                    $shiftType,
                    $entry['start_time'],
                    "shifts.{$index}.shift_type",
                );
            }
        });
    }

    private function replace(
        ?Store $contextStore,
        ?Store $workStore,
        Staff $staff,
        string $shiftDate,
        ?ShiftType $shiftType,
        ?string $startTime,
        string $field,
    ): ?Shift {
        $existing = Shift::query()
            ->where('staff_id', $staff->id)
            ->whereDate('shift_date', $shiftDate)
            ->lockForUpdate()
            ->first();

        $this->validateContextStore($contextStore, $staff, $shiftDate, $existing, $field);

        if ($shiftType === null) {
            $existing?->delete();

            return null;
        }

        $this->validateWorkShift($workStore, $staff, $shiftDate, $shiftType, $startTime, $field);
        $attributes = $this->attributes($workStore, $staff, $shiftDate, $shiftType, $startTime);

        if ($existing === null) {
            return Shift::query()->create($attributes);
        }

        $existing->update($attributes);

        return $existing->fresh();
    }

    private function validateWorkShift(
        ?Store $store,
        Staff $staff,
        string $shiftDate,
        ShiftType $shiftType,
        ?string $startTime,
        string $field,
    ): void {
        if (! $staff->isEmployedOn($shiftDate)) {
            throw ValidationException::withMessages([
                $field => '対象日に在籍していないスタッフへシフトを登録できません。',
            ]);
        }

        if (in_array($shiftType, [ShiftType::Off, ShiftType::Absence], true)) {
            if ($staff->attendanceRecords()
                ->whereDate('work_date', $shiftDate)
                ->exists()) {
                throw ValidationException::withMessages([
                    $field => '勤怠実績が登録されているため、休みまたは急な休みへ変更できません。',
                ]);
            }

            return;
        }

        if ($store === null) {
            throw ValidationException::withMessages([
                $field => '勤務店舗を選択してください。',
            ]);
        }

        if (! $store->is_active) {
            throw ValidationException::withMessages([
                $field => '無効な店舗へ新しいシフトを登録できません。',
            ]);
        }

        if ($store->holidays()->whereDate('holiday_date', $shiftDate)->exists()) {
            throw ValidationException::withMessages([
                $field => '店休日には通常シフトを登録できません。',
            ]);
        }

        if ($shiftType === ShiftType::Time
            && is_string($startTime)
            && ! $store->allowsShiftStartTime($startTime)) {
            throw ValidationException::withMessages([
                $field => '勤務開始時刻は勤務店舗の開店時間から閉店時間までの範囲で選択してください。',
            ]);
        }
    }

    private function validateContextStore(
        ?Store $contextStore,
        Staff $staff,
        string $shiftDate,
        ?Shift $existing,
        string $field,
    ): void {
        if (! $contextStore instanceof Store || ! $contextStore->is_active) {
            throw ValidationException::withMessages([
                $field => '無効な店舗からシフトを編集できません。',
            ]);
        }

        $assigned = $staff->storeAssignments()
            ->where('store_id', $contextStore->id)
            ->effectiveOn($shiftDate)
            ->exists();
        $assignedToAnyActiveStore = $staff->storeAssignments()
            ->effectiveOn($shiftDate)
            ->whereHas('store', fn ($query) => $query->where('is_active', true))
            ->exists();
        $existingAtContext = $existing !== null
            && $existing->shift_type !== ShiftType::Off
            && $existing->store_id === $contextStore->id;

        if (! $assigned && ! $assignedToAnyActiveStore && ! $existingAtContext) {
            throw ValidationException::withMessages([
                $field => '対象日に有効な所属店舗がないスタッフへシフトを登録できません。',
            ]);
        }
    }

    /** @return Collection<int, Store> */
    private function lockStores(Store $contextStore, ?Store $workStore): Collection
    {
        return Store::query()
            ->whereKey(collect([$contextStore->id, $workStore?->id])->filter()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /** @return array<string, int|string|null|ShiftType> */
    private function attributes(
        ?Store $store,
        Staff $staff,
        string $shiftDate,
        ShiftType $shiftType,
        ?string $startTime,
    ): array {
        return [
            'staff_id' => $staff->id,
            'store_id' => in_array($shiftType, [ShiftType::Off, ShiftType::Absence], true)
                ? null
                : $store?->id,
            'shift_date' => $shiftDate,
            'shift_type' => $shiftType,
            'start_time' => $shiftType === ShiftType::Time ? $startTime : null,
        ];
    }
}
