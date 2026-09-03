<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\SelectedStoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SelectedStoreController extends Controller
{
    public function update(Request $request, SelectedStoreService $selectedStores): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where('is_active', true),
            ],
        ]);
        $store = Store::query()->findOrFail((int) $validated['store_id']);
        $selectedStores->remember($request, $store);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "選択店舗を{$store->name}へ変更しました。",
        ]);

        return back();
    }
}
