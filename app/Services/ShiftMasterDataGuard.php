<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffStoreAssignment;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ShiftMasterDataGuard
{
    public function ensureHolidayHasNoWorkShifts(Store $store, string $holidayDate): void
    {
        $shift = Shift::query()
            ->where('store_id', $store->id)
            ->whereDate('shift_date', $holidayDate)
            ->with('staff:id,name')
            ->lockForUpdate()
            ->first();

        if ($shift !== null) {
            throw ValidationException::withMessages([
                'holiday_date' => "{$shift->staff->name}さんのシフトが登録済みです。先に対象日のシフトを解除してください。",
            ]);
        }
    }

    public function ensureStaffPeriodCoversShifts(
        Staff $staff,
        ?string $hiredAt,
        ?string $retiredAt,
    ): void {
        if ($hiredAt === null && $retiredAt === null) {
            return;
        }

        $shift = $staff->shifts()
            ->where(function (Builder $query) use ($hiredAt, $retiredAt): void {
                if ($hiredAt !== null) {
                    $query->whereDate('shift_date', '<', $hiredAt);
                }

                if ($retiredAt !== null) {
                    $method = $hiredAt === null ? 'whereDate' : 'orWhereDate';
                    $query->{$method}('shift_date', '>', $retiredAt);
                }
            })
            ->orderBy('shift_date')
            ->lockForUpdate()
            ->first();

        if ($shift !== null) {
            throw ValidationException::withMessages([
                'hired_at' => "{$shift->shift_date->toDateString()}にシフトがあるため、この在籍期間へ変更できません。",
            ]);
        }
    }

    public function ensureAssignmentPeriodCoversShifts(
        Staff $staff,
        StaffStoreAssignment $assignment,
        int $storeId,
        string $effectiveFrom,
        ?string $effectiveTo,
    ): void {
        $shift = $staff->shifts()
            ->where('store_id', $assignment->store_id)
            ->whereDate('shift_date', '>=', $assignment->effective_from->toDateString())
            ->when(
                $assignment->effective_to !== null,
                fn (Builder $query) => $query->whereDate(
                    'shift_date',
                    '<=',
                    $assignment->effective_to->toDateString(),
                ),
            )
            ->when(
                $storeId === $assignment->store_id,
                fn (Builder $query) => $query->where(function (Builder $query) use ($effectiveFrom, $effectiveTo): void {
                    $query->whereDate('shift_date', '<', $effectiveFrom);

                    if ($effectiveTo !== null) {
                        $query->orWhereDate('shift_date', '>', $effectiveTo);
                    }
                }),
            )
            ->orderBy('shift_date')
            ->lockForUpdate()
            ->first();

        if ($shift !== null) {
            throw ValidationException::withMessages([
                'effective_from' => "{$shift->shift_date->toDateString()}にこの店舗のシフトがあるため、所属期間を変更できません。",
            ]);
        }
    }
}
