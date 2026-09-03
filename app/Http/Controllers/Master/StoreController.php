<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('stores/index', [
            'stores' => Store::query()
                ->withCount('holidays')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store): array => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'opening_time' => $this->formatTime($store->opening_time),
                    'closing_time' => $this->formatTime($store->closing_time),
                    'is_active' => $store->is_active,
                    'holidays_count' => $store->holidays_count,
                ]),
        ]);
    }

    public function store(StoreRequest $request): RedirectResponse
    {
        $store = Store::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => '店舗を登録しました。']);

        return to_route('stores.edit', $store);
    }

    public function edit(Request $request, Store $store): Response
    {
        $validated = $request->validate([
            'holiday_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $holidayMonth = Carbon::createFromFormat(
            '!Y-m',
            $validated['holiday_month'] ?? today()->format('Y-m'),
        );

        return Inertia::render('stores/edit', [
            'holiday_month' => $holidayMonth->format('Y-m'),
            'holiday_month_label' => $holidayMonth->isoFormat('YYYY年M月'),
            'holiday_month_end' => $holidayMonth->copy()->endOfMonth()->toDateString(),
            'previous_holiday_month' => $holidayMonth->copy()->subMonth()->format('Y-m'),
            'next_holiday_month' => $holidayMonth->copy()->addMonth()->format('Y-m'),
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'opening_time' => $this->formatTime($store->opening_time),
                'closing_time' => $this->formatTime($store->closing_time),
                'is_active' => $store->is_active,
                'holidays' => $store->holidays()
                    ->whereDate(
                        'holiday_date',
                        '>=',
                        $holidayMonth->copy()->startOfMonth()->toDateString(),
                    )
                    ->whereDate(
                        'holiday_date',
                        '<=',
                        $holidayMonth->copy()->endOfMonth()->toDateString(),
                    )
                    ->oldest('holiday_date')
                    ->get()
                    ->map(fn ($holiday): array => [
                        'id' => $holiday->id,
                        'holiday_date' => $holiday->holiday_date->toDateString(),
                    ]),
            ],
        ]);
    }

    public function update(StoreRequest $request, Store $store): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($store, $data): void {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);
            $store->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => '店舗情報を更新しました。']);

        return back();
    }

    private function formatTime(string $time): string
    {
        return substr($time, 0, 5);
    }
}
