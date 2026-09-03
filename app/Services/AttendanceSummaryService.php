<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceSummaryService
{
    /** @return list<array{staff_id: int, name: string, attendance_days: int, working_minutes: int}> */
    public function employeeMonthly(Carbon $month, ?Store $store = null): array
    {
        return array_values(DB::table('attendance_records')
            ->join('staffs', 'staffs.id', '=', 'attendance_records.staff_id')
            ->where('staffs.employment_type', EmploymentType::Employee->value)
            ->whereBetween('attendance_records.work_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->when($store !== null, fn ($query) => $query->where('attendance_records.store_id', $store->id))
            ->groupBy('attendance_records.staff_id', 'staffs.name')
            ->orderBy('attendance_records.staff_id')
            ->get([
                'attendance_records.staff_id',
                'staffs.name',
                DB::raw('COUNT(*) AS attendance_days'),
                DB::raw('SUM(attendance_records.working_minutes) AS working_minutes'),
            ])
            ->map(function (object $row): array {
                $values = (array) $row;

                return [
                    'staff_id' => (int) $values['staff_id'],
                    'name' => (string) $values['name'],
                    'attendance_days' => (int) $values['attendance_days'],
                    'working_minutes' => (int) $values['working_minutes'],
                ];
            })
            ->values()
            ->all());
    }
}
