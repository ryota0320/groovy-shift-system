<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
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

    public function edit(Store $store): Response
    {
        return Inertia::render('stores/edit', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'is_active' => $store->is_active,
                'holidays' => $store->holidays()
                    ->latest('holiday_date')
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
        $store->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => '店舗情報を更新しました。']);

        return back();
    }
}
