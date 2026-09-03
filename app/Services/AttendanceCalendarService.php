<?php

namespace App\Services;

use App\Enums\ShiftType;
use App\Models\AttendanceRecord;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceCalendarService
{
    /** @return array<string, mixed> */
    public function daily(Store $store, Carbon $workDate): array
    {
        $date = $workDate->toDateString();
        $isHoliday = $store->holidays()->whereDate('holiday_date', $date)->exists();
        $assignedStaffIds = Staff::query()
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $date);
            })
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('retired_at')->orWhereDate('retired_at', '>=', $date);
            })
            ->whereHas('storeAssignments', fn ($query) => $query
                ->where('store_id', $store->id)
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date)))
            ->pluck('id');
        $shifts = Shift::query()
            ->whereDate('shift_date', $date)
            ->where(fn (Builder $query) => $query
                ->where('store_id', $store->id)
                ->orWhereIn('staff_id', $assignedStaffIds))
            ->with('store:id,name')
            ->get()
            ->keyBy('staff_id');
        $selectedAttendances = AttendanceRecord::query()
            ->where('store_id', $store->id)
            ->whereDate('work_date', $date)
            ->get()
            ->keyBy('staff_id');
        $rowStaffIds = $assignedStaffIds
            ->merge($shifts->keys())
            ->merge($selectedAttendances->keys())
            ->unique();
        $staffs = Staff::query()
            ->whereKey($rowStaffIds)
            ->with([
                'storeAssignments' => fn ($query) => $query
                    ->where('store_id', $store->id)
                    ->effectiveOn($date),
                'attendanceRecords' => fn ($query) => $query
                    ->whereDate('work_date', $date)
                    ->with('store:id,name'),
            ])
            ->inDisplayOrder($store->id)
            ->get();

        $rows = $staffs->map(function (Staff $staff) use ($date, $store, $shifts, $selectedAttendances): array {
            /** @var Shift|null $shift */
            $shift = $shifts->get($staff->id);
            /** @var AttendanceRecord|null $attendance */
            $attendance = $selectedAttendances->get($staff->id);
            $source = $shift !== null
                ? 'scheduled'
                : ($attendance !== null ? 'sudden' : 'unplanned');
            $otherAttendance = $staff->attendanceRecords
                ->first(fn (AttendanceRecord $record): bool => $record->store_id !== $store->id);
            $otherWorkShift = $shift !== null
                && in_array($shift->shift_type, [ShiftType::Time, ShiftType::Early], true)
                && $shift->store_id !== $store->id
                ? $shift
                : null;
            $conflictStore = $otherAttendance?->store->name ?? $otherWorkShift?->store?->name;
            $eligible = $staff->isEmployedOn($date)
                && ($staff->storeAssignments->isNotEmpty() || $shift !== null);

            return [
                'staff_id' => $staff->id,
                'name' => $staff->name,
                'employment_type' => $staff->employment_type->value,
                'employment_type_label' => $staff->employment_type->label(),
                'source' => $source,
                'eligible' => $eligible,
                'editable' => ! in_array($shift?->shift_type, [ShiftType::Off, ShiftType::Absence], true)
                    && ($attendance !== null || ($store->is_active && $eligible && $conflictStore === null)),
                'conflict_store' => $conflictStore,
                'shift' => $this->shiftPayload($shift, $source, $store),
                'attendance' => $this->attendancePayload($attendance, $date, $shift),
            ];
        })->values();

        return [
            'is_holiday' => $isHoliday,
            'staffs' => $rows->all(),
            'addable_staffs' => $this->addableStaffs($store, $date, $rowStaffIds),
            'summary' => [
                'attendance_count' => $selectedAttendances->count(),
                'working_minutes' => $selectedAttendances->sum('working_minutes'),
                'late_night_minutes' => $selectedAttendances->sum('late_night_minutes'),
            ],
        ];
    }

    /**
     * @param  Collection<int, int|string>  $excludedStaffIds
     * @return list<array{id: int, name: string, employment_type: string, employment_type_label: string, assignment_store_names: list<string>}>
     */
    private function addableStaffs(Store $store, string $date, Collection $excludedStaffIds): array
    {
        if (! $store->is_active) {
            return [];
        }

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
            ->whereDoesntHave('attendanceRecords', fn ($query) => $query->whereDate('work_date', $date))
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
                'name' => $staff->name,
                'employment_type' => $staff->employment_type->value,
                'employment_type_label' => $staff->employment_type->label(),
                'assignment_store_names' => array_values($staff->storeAssignments
                    ->pluck('store.name')
                    ->filter()
                    ->map(fn (mixed $name): string => (string) $name)
                    ->values()
                    ->all()),
            ])
            ->values()
            ->all());
    }

    /** @return array{type: string|null, display: string, start_offset_minutes: int|null} */
    private function shiftPayload(
        ?Shift $shift,
        string $source = 'scheduled',
        ?Store $contextStore = null,
    ): array {
        if ($shift === null) {
            return [
                'type' => null,
                'display' => $source === 'unplanned' ? 'シフト未設定' : '急な出勤',
                'start_offset_minutes' => null,
            ];
        }

        if ($shift->shift_type === ShiftType::Early) {
            $prefix = $contextStore !== null && $shift->store_id !== $contextStore->id
                ? $shift->store?->name.' '
                : '';

            return ['type' => 'early', 'display' => $prefix.'早番', 'start_offset_minutes' => null];
        }

        if ($shift->shift_type === ShiftType::Absence) {
            return ['type' => 'absence', 'display' => '急な休み', 'start_offset_minutes' => null];
        }

        if ($shift->shift_type === ShiftType::Off) {
            return ['type' => 'off', 'display' => '休み', 'start_offset_minutes' => null];
        }

        $start = substr((string) $shift->start_time, 0, 5);
        [$hour, $minute] = array_map('intval', explode(':', $start));
        $offset = ($hour * 60) + $minute;

        if ($hour <= 10) {
            $offset += 24 * 60;
        }
        $prefix = $contextStore !== null && $shift->store_id !== $contextStore->id
            ? $shift->store?->name.' '
            : '';

        return [
            'type' => 'time',
            'display' => $prefix.$hour.':'.sprintf('%02d', $minute),
            'start_offset_minutes' => $offset,
        ];
    }

    /** @return array<string, mixed>|null */
    private function attendancePayload(?AttendanceRecord $attendance, string $date, ?Shift $shift): ?array
    {
        if ($attendance === null) {
            return null;
        }

        $start = Carbon::parse($date)->startOfDay();
        $clockInOffset = (int) $start->diffInMinutes($attendance->clock_in_at, false);
        $clockOutOffset = (int) $start->diffInMinutes($attendance->clock_out_at, false);
        $shiftPayload = $this->shiftPayload($shift);
        $scheduledOffset = $shiftPayload['start_offset_minutes'];
        $warning = $scheduledOffset !== null && abs($clockInOffset - $scheduledOffset) >= 15
            ? "シフト予定{$shiftPayload['display']}と実出勤{$this->offsetLabel($clockInOffset)}が異なります。"
            : null;

        return [
            'id' => $attendance->id,
            'clock_in_offset_minutes' => $clockInOffset,
            'clock_out_offset_minutes' => $clockOutOffset,
            'clock_in_label' => $this->offsetLabel($clockInOffset),
            'clock_out_label' => $this->offsetLabel($clockOutOffset),
            'working_minutes' => $attendance->working_minutes,
            'late_night_minutes' => $attendance->late_night_minutes,
            'warning' => $warning,
        ];
    }

    private function offsetLabel(int $offset): string
    {
        $minutes = $offset % (24 * 60);

        return intdiv($minutes, 60).':'.sprintf('%02d', $minutes % 60);
    }
}
