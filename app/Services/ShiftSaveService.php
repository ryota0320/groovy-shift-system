<?php

namespace App\Services;

use App\Enums\ShiftType;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftSaveService
{
    public function create(
        Store $store,
        Staff $staff,
        string $shiftDate,
        ShiftType $shiftType,
        ?string $startTime,
    ): Shift {
        return DB::transaction(function () use ($store, $staff, $shiftDate, $shiftType, $startTime): Shift {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);
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

            $this->validateWorkShift($store, $staff, $shiftDate, $shiftType, 'shift_type');

            return Shift::query()->create($this->attributes(
                $store,
                $staff,
                $shiftDate,
                $shiftType,
                $startTime,
            ));
        });
    }

    public function saveCell(
        Store $store,
        Staff $staff,
        string $shiftDate,
        ?ShiftType $shiftType,
        ?string $startTime,
    ): ?Shift {
        return DB::transaction(function () use ($store, $staff, $shiftDate, $shiftType, $startTime): ?Shift {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);
            Staff::query()->lockForUpdate()->findOrFail($staff->id);

            return $this->replace(
                $store,
                $staff,
                $shiftDate,
                $shiftType,
                $startTime,
                'shift_type',
            );
        });
    }

    /**
     * @param  list<array{staff_id: int, shift_type: string|null, start_time: string|null}>  $entries
     */
    public function saveDaily(Store $store, string $shiftDate, array $entries): void
    {
        DB::transaction(function () use ($store, $shiftDate, $entries): void {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);
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

                $this->replace(
                    $store,
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
        Store $store,
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

        if ($existing !== null
            && $existing->shift_type !== ShiftType::Off
            && $existing->store_id !== $store->id) {
            throw ValidationException::withMessages([
                $field => 'このスタッフは同じ営業日に別店舗のシフトが登録されています。',
            ]);
        }

        if ($shiftType === null) {
            $existing?->delete();

            return null;
        }

        $this->validateWorkShift($store, $staff, $shiftDate, $shiftType, $field);
        $attributes = $this->attributes($store, $staff, $shiftDate, $shiftType, $startTime);

        if ($existing === null) {
            return Shift::query()->create($attributes);
        }

        $existing->update($attributes);

        return $existing->fresh();
    }

    private function validateWorkShift(
        Store $store,
        Staff $staff,
        string $shiftDate,
        ShiftType $shiftType,
        string $field,
    ): void {
        if (! $staff->isEmployedOn($shiftDate)) {
            throw ValidationException::withMessages([
                $field => '対象日に在籍していないスタッフへシフトを登録できません。',
            ]);
        }

        if ($shiftType === ShiftType::Off) {
            return;
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

        $assigned = $staff->storeAssignments()
            ->where('store_id', $store->id)
            ->effectiveOn($shiftDate)
            ->exists();

        if (! $assigned) {
            throw ValidationException::withMessages([
                $field => '対象日に所属していない店舗へシフトを登録できません。',
            ]);
        }
    }

    /** @return array<string, int|string|null|ShiftType> */
    private function attributes(
        Store $store,
        Staff $staff,
        string $shiftDate,
        ShiftType $shiftType,
        ?string $startTime,
    ): array {
        return [
            'staff_id' => $staff->id,
            'store_id' => $shiftType === ShiftType::Off ? null : $store->id,
            'shift_date' => $shiftDate,
            'shift_type' => $shiftType,
            'start_time' => $shiftType === ShiftType::Time ? $startTime : null,
        ];
    }
}
