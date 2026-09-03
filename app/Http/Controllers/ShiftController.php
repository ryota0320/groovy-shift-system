<?php

namespace App\Http\Controllers;

use App\Enums\ShiftType;
use App\Http\Requests\MonthlyShiftStaffRequest;
use App\Http\Requests\ShiftCellRequest;
use App\Http\Requests\ShiftDailyRequest;
use App\Http\Requests\ShiftStaffOrderRequest;
use App\Models\MonthlyShiftStaffAddition;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffStoreDisplayOrder;
use App\Models\Store;
use App\Services\BusinessDateService;
use App\Services\SelectedStoreService;
use App\Services\ShiftCalendarService;
use App\Services\ShiftSaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function __construct(
        private ShiftCalendarService $calendar,
        private ShiftSaveService $shifts,
        private SelectedStoreService $selectedStores,
        private BusinessDateService $businessDates,
    ) {}

    public function monthly(Request $request): Response
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $store = $this->selectedStores->resolveForPage(
            $request,
            $validated['store_id'] ?? null,
        );
        $month = Carbon::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'));
        $calendar = $store === null
            ? ['days' => [], 'staffs' => [], 'addable_staffs' => []]
            : $this->calendar->monthly($store, $month);

        return Inertia::render('shifts/monthly', [
            'stores' => $this->stores(),
            'selected_store' => $store,
            'month' => $month->format('Y-m'),
            ...$calendar,
        ]);
    }

    public function daily(Request $request): Response
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'date' => ['nullable', 'date'],
        ]);
        $store = $this->selectedStores->resolveForPage(
            $request,
            $validated['store_id'] ?? null,
        );
        $date = Carbon::parse($validated['date'] ?? $this->businessDates->current())->startOfDay();
        $calendar = $store === null
            ? ['is_holiday' => false, 'staffs' => [], 'addable_staffs' => []]
            : $this->calendar->daily($store, $date);

        return Inertia::render('shifts/daily', [
            'stores' => $this->stores(),
            'selected_store' => $store,
            'date' => $date->toDateString(),
            'previous_date' => $date->copy()->subDay()->toDateString(),
            'next_date' => $date->copy()->addDay()->toDateString(),
            'weekday' => ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek],
            ...$calendar,
        ]);
    }

    public function store(ShiftCellRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (! isset($data['shift_type'])) {
            throw ValidationException::withMessages([
                'shift_type' => 'シフト種別を選択してください。',
            ]);
        }

        $type = ShiftType::from($data['shift_type']);
        $contextStore = $this->findStore((int) $data['store_id']);
        $workStore = $this->workStore($data['work_store_id'] ?? null, $type);
        $this->shifts->create(
            $contextStore,
            $this->staff((int) $data['staff_id']),
            $data['shift_date'],
            $type,
            $data['start_time'] ?? null,
            $workStore,
        );

        return $this->success('シフトを登録しました。');
    }

    public function saveCell(ShiftCellRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $type = isset($data['shift_type'])
            ? ShiftType::from($data['shift_type'])
            : null;
        $this->shifts->saveCell(
            $this->findStore((int) $data['store_id']),
            $this->staff((int) $data['staff_id']),
            $data['shift_date'],
            $type,
            $data['start_time'] ?? null,
            $this->workStore($data['work_store_id'] ?? null, $type),
        );

        return $this->success('シフトを更新しました。');
    }

    public function saveDaily(ShiftDailyRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->shifts->saveDaily(
            $this->findStore((int) $data['store_id']),
            $data['shift_date'],
            $data['shifts'],
        );

        return $this->success('日別シフトを保存しました。');
    }

    public function saveMonthlyOrder(ShiftStaffOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $storeId = (int) $data['store_id'];
        $month = isset($data['month'])
            ? Carbon::createFromFormat('!Y-m', $data['month'])
            : null;
        /** @var list<int|string> $staffIds */
        $staffIds = $data['staff_ids'];
        $submittedStaffIds = collect($staffIds)->map(fn (int|string $id): int => (int) $id);

        DB::transaction(function () use ($month, $storeId, $submittedStaffIds): void {
            $existingStaffIds = StaffStoreDisplayOrder::query()
                ->where('store_id', $storeId)
                ->orderBy('position')
                ->orderBy('staff_id')
                ->lockForUpdate()
                ->pluck('staff_id');
            $orderedStaffIds = $submittedStaffIds
                ->merge($existingStaffIds)
                ->unique()
                ->values();
            $timestamp = now();

            StaffStoreDisplayOrder::query()->upsert(
                $orderedStaffIds
                    ->map(fn (int $staffId, int $position): array => [
                        'store_id' => $storeId,
                        'staff_id' => $staffId,
                        'position' => $position,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->all(),
                ['store_id', 'staff_id'],
                ['position', 'updated_at'],
            );

            if ($month !== null) {
                foreach ($submittedStaffIds as $position => $staffId) {
                    MonthlyShiftStaffAddition::query()
                        ->where('store_id', $storeId)
                        ->where('staff_id', $staffId)
                        ->whereDate('month', $month->toDateString())
                        ->update(['position' => $position]);
                }
            }
        });

        return back();
    }

    public function addMonthlyStaff(MonthlyShiftStaffRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $store = $this->findStore((int) $data['store_id']);
        $staff = $this->staff((int) $data['staff_id']);
        $month = Carbon::createFromFormat('!Y-m', $data['month']);
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();

        if (! $store->is_active) {
            throw ValidationException::withMessages([
                'staff_id' => '無効な店舗の月間シフトへスタッフを追加できません。',
            ]);
        }
        $employedDuringMonth = ($staff->hired_at === null || $staff->hired_at->lessThanOrEqualTo($periodEnd))
            && ($staff->retired_at === null || $staff->retired_at->greaterThanOrEqualTo($periodStart));
        $assignedToActiveStoreDuringMonth = $staff->storeAssignments()
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $periodStart->toDateString()))
            ->whereHas('store', fn ($query) => $query->where('is_active', true))
            ->exists();

        if (! $employedDuringMonth || ! $assignedToActiveStoreDuringMonth) {
            throw ValidationException::withMessages([
                'staff_id' => '対象月に在籍し、有効な店舗へ所属するスタッフを選択してください。',
            ]);
        }
        $visibleStaffIds = collect($this->calendar->monthly($store, $month)['staffs'])->pluck('id');
        if ($visibleStaffIds->contains($staff->id)) {
            throw ValidationException::withMessages([
                'staff_id' => 'このスタッフは月間シフトに既に表示されています。',
            ]);
        }

        DB::transaction(function () use ($month, $staff, $store, $visibleStaffIds): void {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $existingStaffIds = StaffStoreDisplayOrder::query()
                ->where('store_id', $store->id)
                ->orderBy('position')
                ->orderBy('staff_id')
                ->lockForUpdate()
                ->pluck('staff_id');
            $orderedStaffIds = $visibleStaffIds
                ->push($staff->id)
                ->merge($existingStaffIds)
                ->unique()
                ->values();
            $timestamp = now();

            StaffStoreDisplayOrder::query()->upsert(
                $orderedStaffIds
                    ->map(fn (int $staffId, int $position): array => [
                        'store_id' => $store->id,
                        'staff_id' => $staffId,
                        'position' => $position,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->all(),
                ['store_id', 'staff_id'],
                ['position', 'updated_at'],
            );

            $lastPosition = MonthlyShiftStaffAddition::query()
                ->where('store_id', $store->id)
                ->whereDate('month', $month->toDateString())
                ->lockForUpdate()
                ->max('position');
            MonthlyShiftStaffAddition::query()->create([
                'store_id' => $store->id,
                'staff_id' => $staff->id,
                'month' => $month->toDateString(),
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition + 1),
            ]);
        });

        return $this->success("{$staff->name}さんを月間シフトの末尾へ追加しました。");
    }

    public function removeMonthlyStaff(MonthlyShiftStaffRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $store = $this->findStore((int) $data['store_id']);
        $staff = $this->staff((int) $data['staff_id']);
        $month = Carbon::createFromFormat('!Y-m', $data['month']);
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();
        $assignedToContextStore = $staff->storeAssignments()
            ->where('store_id', $store->id)
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $periodStart->toDateString()))
            ->exists();

        if ($assignedToContextStore) {
            throw ValidationException::withMessages([
                'staff_id' => '対象月に表示店舗へ所属しているスタッフは一覧から削除できません。',
            ]);
        }

        $deletedShiftCount = DB::transaction(function () use ($periodEnd, $periodStart, $staff, $store): int {
            $addition = MonthlyShiftStaffAddition::query()
                ->where('store_id', $store->id)
                ->where('staff_id', $staff->id)
                ->whereDate('month', $periodStart->toDateString())
                ->lockForUpdate()
                ->first();
            $shiftQuery = Shift::query()
                ->where('staff_id', $staff->id)
                ->whereBetween('shift_date', [
                    $periodStart->toDateString(),
                    $periodEnd->toDateString(),
                ]);
            $hasShifts = (clone $shiftQuery)->exists();

            if ($addition === null && ! $hasShifts) {
                throw ValidationException::withMessages([
                    'staff_id' => '対象月の一覧から削除できるデータがありません。',
                ]);
            }

            $deletedShiftCount = $shiftQuery->delete();
            $addition?->delete();

            return $deletedShiftCount;
        });

        return $this->success("{$staff->name}さんを対象月の一覧から削除し、登録済みシフト{$deletedShiftCount}件を削除しました。");
    }

    /** @return list<array{id: int, name: string, opening_time: string, closing_time: string, is_active: bool}> */
    private function stores(): array
    {
        return array_values(Store::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'opening_time', 'closing_time', 'is_active'])
            ->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'opening_time' => substr($store->opening_time, 0, 5),
                'closing_time' => substr($store->closing_time, 0, 5),
                'is_active' => $store->is_active,
            ])
            ->values()
            ->all());
    }

    private function findStore(int $storeId): Store
    {
        return Store::query()->findOrFail($storeId);
    }

    private function staff(int $staffId): Staff
    {
        return Staff::query()->findOrFail($staffId);
    }

    private function workStore(mixed $storeId, ?ShiftType $type): ?Store
    {
        if ($type === null || in_array($type, [ShiftType::Off, ShiftType::Absence], true) || $storeId === null) {
            return null;
        }

        return $this->findStore((int) $storeId);
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
