<?php

namespace App\Http\Controllers;

use App\Enums\ShiftType;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Store;
use App\Services\SelectedStoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SelectedStoreService $selectedStores): Response
    {
        $selectedStore = $selectedStores->current($request);
        $today = today();
        $date = $today->toDateString();
        $weekday = ['日', '月', '火', '水', '木', '金', '土'][$today->dayOfWeek];
        $todayShiftCount = 0;
        $attendanceMissingCount = 0;
        $assignedCount = 0;
        $assignedWorkingCount = 0;
        $helpCount = 0;
        $offCount = 0;
        $otherStoreCount = 0;
        $unscheduledCount = 0;

        if ($selectedStore !== null) {
            $assignedStaffIds = Staff::query()
                ->where(fn (Builder $query) => $query
                    ->whereNull('hired_at')
                    ->orWhereDate('hired_at', '<=', $date))
                ->where(fn (Builder $query) => $query
                    ->whereNull('retired_at')
                    ->orWhereDate('retired_at', '>=', $date))
                ->whereHas('storeAssignments', fn ($query) => $query
                    ->where('store_id', $selectedStore->id)
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $date)))
                ->pluck('id');
            $workingShiftStaffIds = Shift::query()
                ->where('store_id', $selectedStore->id)
                ->whereDate('shift_date', $date)
                ->whereIn('shift_type', [ShiftType::Time->value, ShiftType::Early->value])
                ->pluck('staff_id');
            $assignedShifts = Shift::query()
                ->whereIn('staff_id', $assignedStaffIds)
                ->whereDate('shift_date', $date)
                ->get(['staff_id', 'store_id', 'shift_type']);
            $attendanceStaffIds = $selectedStore->attendanceRecords()
                ->whereDate('work_date', $date)
                ->pluck('staff_id');

            $todayShiftCount = $workingShiftStaffIds->count();
            $attendanceMissingCount = $workingShiftStaffIds->diff($attendanceStaffIds)->count();
            $assignedCount = $assignedStaffIds->count();
            $assignedWorkingCount = $workingShiftStaffIds->intersect($assignedStaffIds)->count();
            $helpCount = $workingShiftStaffIds->diff($assignedStaffIds)->count();
            $offCount = $assignedShifts
                ->whereIn('shift_type', [ShiftType::Off, ShiftType::Absence])
                ->count();
            $otherStoreCount = $assignedShifts
                ->whereIn('shift_type', [ShiftType::Time, ShiftType::Early])
                ->where('store_id', '!=', $selectedStore->id)
                ->count();
            $unscheduledCount = $assignedStaffIds
                ->diff($assignedShifts->pluck('staff_id'))
                ->count();
        }

        return Inertia::render('dashboard', [
            'stores' => Store::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'opening_time', 'closing_time', 'is_active']),
            'selected_store' => $selectedStore,
            'today' => $today->toDateString(),
            'today_label' => $today->format("Y年n月j日（{$weekday}）"),
            'today_shift_count' => $todayShiftCount,
            'attendance_missing_count' => $attendanceMissingCount,
            'today_assigned_count' => $assignedCount,
            'today_assigned_working_count' => $assignedWorkingCount,
            'today_help_count' => $helpCount,
            'today_off_count' => $offCount,
            'today_other_store_count' => $otherStoreCount,
            'today_unscheduled_count' => $unscheduledCount,
        ]);
    }
}
