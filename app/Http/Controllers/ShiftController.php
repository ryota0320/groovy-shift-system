<?php

namespace App\Http\Controllers;

use App\Enums\ShiftType;
use App\Http\Requests\ShiftCellRequest;
use App\Http\Requests\ShiftDailyRequest;
use App\Http\Requests\ShiftStaffOrderRequest;
use App\Models\Staff;
use App\Models\StaffStoreDisplayOrder;
use App\Models\Store;
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
            ? ['days' => [], 'staffs' => []]
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
        $date = Carbon::parse($validated['date'] ?? today())->startOfDay();
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
        /** @var list<int|string> $staffIds */
        $staffIds = $data['staff_ids'];
        $submittedStaffIds = collect($staffIds)->map(fn (int|string $id): int => (int) $id);

        DB::transaction(function () use ($storeId, $submittedStaffIds): void {
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
        });

        return back();
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
