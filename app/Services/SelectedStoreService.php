<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Request;

class SelectedStoreService
{
    private const SESSION_KEY = 'selected_store_id';

    public function current(Request $request): ?Store
    {
        $selectedStoreId = $request->session()->get(self::SESSION_KEY);

        if (is_numeric($selectedStoreId)) {
            $selectedStore = Store::query()
                ->whereKey((int) $selectedStoreId)
                ->where('is_active', true)
                ->first();

            if ($selectedStore !== null) {
                return $selectedStore;
            }

            $request->session()->forget(self::SESSION_KEY);
        }

        $firstStore = Store::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        if ($firstStore !== null) {
            $this->remember($request, $firstStore);
        }

        return $firstStore;
    }

    public function resolveForPage(Request $request, mixed $requestedStoreId): ?Store
    {
        if ($requestedStoreId === null) {
            return $this->current($request);
        }

        $store = Store::query()->findOrFail((int) $requestedStoreId);

        if ($store->is_active) {
            $this->remember($request, $store);
        }

        return $store;
    }

    public function remember(Request $request, Store $store): void
    {
        $request->session()->put(self::SESSION_KEY, $store->id);
    }
}
