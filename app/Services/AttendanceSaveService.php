<?php

namespace App\Services;

use App\Enums\ShiftType;
use App\Models\AttendanceRecord;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AttendanceSaveService
{
    public function __construct(
        private AttendanceTimeService $times,
        private PayrollRecalculationService $payrolls,
    ) {}

    /**
     * @param  list<array{staff_id: int, clock_in_offset_minutes: int, clock_out_offset_minutes: int}>  $records
     */
    public function saveDaily(
        Store $store,
        string $workDate,
        array $records,
        bool $holidayConfirmed,
    ): void {
        DB::transaction(function () use ($store, $workDate, $records, $holidayConfirmed): void {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);
            $staffIds = collect($records)
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
            $existingRecords = AttendanceRecord::query()
                ->whereIn('staff_id', $staffIds)
                ->whereDate('work_date', $workDate)
                ->lockForUpdate()
                ->get()
                ->keyBy('staff_id');
            $isHoliday = $store->holidays()->whereDate('holiday_date', $workDate)->exists();

            if ($isHoliday && ! $holidayConfirmed) {
                throw ValidationException::withMessages([
                    'holiday_confirmed' => 'この店舗は店休日です。確認後に勤務実績を登録してください。',
                ]);
            }

            foreach ($records as $index => $record) {
                $staff = $staffs->get($record['staff_id']);
                if (! $staff instanceof Staff) {
                    throw ValidationException::withMessages([
                        "records.{$index}.staff_id" => 'スタッフが見つかりません。',
                    ]);
                }

                $existing = $existingRecords->get($staff->id);
                if ($existing !== null && $existing->store_id !== $store->id) {
                    throw ValidationException::withMessages([
                        "records.{$index}.staff_id" => '同じ営業日に別店舗の勤怠が登録されています。',
                    ]);
                }

                if ($existing === null) {
                    $this->validateNewRecord($store, $staff, $workDate, $index);
                }

                try {
                    $time = $this->times->calculate(
                        $workDate,
                        $record['clock_in_offset_minutes'],
                        $record['clock_out_offset_minutes'],
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        "records.{$index}.clock_out_offset_minutes" => $exception->getMessage(),
                    ]);
                }

                $values = [
                    'store_id' => $store->id,
                    'clock_in_at' => $time->clockInAt,
                    'clock_out_at' => $time->clockOutAt,
                    'working_minutes' => $time->workingMinutes,
                    'late_night_minutes' => $time->lateNightMinutes,
                ];

                if ($existing instanceof AttendanceRecord) {
                    $existing->update($values);
                } else {
                    AttendanceRecord::query()->create([
                        'staff_id' => $staff->id,
                        'work_date' => $workDate,
                        ...$values,
                    ]);
                }
                $this->payrolls->markMonth($staff, $workDate);
            }
        });
    }

    public function delete(AttendanceRecord $record): void
    {
        DB::transaction(function () use ($record): void {
            Store::query()->lockForUpdate()->findOrFail($record->store_id);
            $staff = Staff::query()->lockForUpdate()->findOrFail($record->staff_id);
            $record = AttendanceRecord::query()->lockForUpdate()->findOrFail($record->id);
            $workDate = $record->work_date->toDateString();
            $record->delete();
            $this->payrolls->markMonth($staff, $workDate);
        });
    }

    private function validateNewRecord(Store $store, Staff $staff, string $workDate, int $index): void
    {
        if (! $store->is_active) {
            throw ValidationException::withMessages([
                "records.{$index}.staff_id" => '無効な店舗へ新しい勤怠を登録できません。',
            ]);
        }

        if (! $staff->isEmployedOn($workDate)) {
            throw ValidationException::withMessages([
                "records.{$index}.staff_id" => '対象日に在籍していないスタッフへ勤怠を登録できません。',
            ]);
        }

        $nonWorkingShift = Shift::query()
            ->where('staff_id', $staff->id)
            ->whereDate('shift_date', $workDate)
            ->whereIn('shift_type', [ShiftType::Off->value, ShiftType::Absence->value])
            ->exists();

        if ($nonWorkingShift) {
            throw ValidationException::withMessages([
                "records.{$index}.staff_id" => '休みまたは急な休みのスタッフへ勤怠を登録できません。',
            ]);
        }

        $hasActiveAssignment = $staff->storeAssignments()
            ->effectiveOn($workDate)
            ->whereHas('store', fn ($query) => $query->where('is_active', true))
            ->exists();
        $scheduledHelp = Shift::query()
            ->where('staff_id', $staff->id)
            ->where('store_id', $store->id)
            ->whereDate('shift_date', $workDate)
            ->exists();

        if (! $hasActiveAssignment && ! $scheduledHelp) {
            throw ValidationException::withMessages([
                "records.{$index}.staff_id" => '対象日に有効な所属店舗がないスタッフへ勤怠を登録できません。',
            ]);
        }
    }
}
