<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\Staff;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class PayrollRecalculationService
{
    public function markMonth(Staff|int $staff, DateTimeInterface|string $workDate): void
    {
        $date = Carbon::parse($workDate);

        Payroll::query()
            ->where('staff_id', $staff instanceof Staff ? $staff->id : $staff)
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->update(['needs_recalculation' => true]);
    }

    public function markStaff(Staff|int $staff): void
    {
        Payroll::query()
            ->where('staff_id', $staff instanceof Staff ? $staff->id : $staff)
            ->update(['needs_recalculation' => true]);
    }

    public function markAll(): void
    {
        Payroll::query()->update(['needs_recalculation' => true]);
    }
}
