<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceDailyRequest;
use App\Models\AttendanceRecord;
use App\Models\Store;
use App\Services\AttendanceCalendarService;
use App\Services\AttendanceSaveService;
use App\Services\BusinessDateService;
use App\Services\SelectedStoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceCalendarService $calendar,
        private AttendanceSaveService $attendance,
        private SelectedStoreService $selectedStores,
        private BusinessDateService $businessDates,
    ) {}

    public function daily(Request $request): Response
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'date' => ['nullable', 'date'],
        ]);
        $store = $this->selectedStores->resolveForPage($request, $validated['store_id'] ?? null);
        $date = Carbon::parse($validated['date'] ?? $this->businessDates->current())->startOfDay();
        $calendar = $store === null
            ? [
                'is_holiday' => false,
                'staffs' => [],
                'addable_staffs' => [],
                'summary' => ['attendance_count' => 0, 'working_minutes' => 0, 'late_night_minutes' => 0],
            ]
            : $this->calendar->daily($store, $date);

        return Inertia::render('attendance/daily', [
            'stores' => Store::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'opening_time', 'closing_time', 'is_active']),
            'selected_store' => $store,
            'date' => $date->toDateString(),
            'previous_date' => $date->copy()->subDay()->toDateString(),
            'next_date' => $date->copy()->addDay()->toDateString(),
            'weekday' => ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek],
            ...$calendar,
        ]);
    }

    public function saveDaily(AttendanceDailyRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->attendance->saveDaily(
            Store::query()->findOrFail((int) $data['store_id']),
            $data['work_date'],
            $data['records'],
            (bool) ($data['holiday_confirmed'] ?? false),
        );

        return $this->success('日次勤怠を保存しました。');
    }

    public function destroy(AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $this->attendance->delete($attendanceRecord);

        return $this->success('勤怠を削除しました。');
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
