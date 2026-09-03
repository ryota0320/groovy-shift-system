<?php

namespace App\Http\Controllers;

use App\Enums\ShiftType;
use App\Http\Requests\ShiftCellRequest;
use App\Http\Requests\ShiftDailyRequest;
use App\Models\Staff;
use App\Models\Store;
use App\Services\SelectedStoreService;
use App\Services\ShiftCalendarService;
use App\Services\ShiftSaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    /** @return list<array{id: int, name: string, is_active: bool}> */
    private function stores(): array
    {
        return array_values(Store::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
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
