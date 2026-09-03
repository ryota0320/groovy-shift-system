<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreHolidayRequest;
use App\Models\Store;
use App\Models\StoreHoliday;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StoreHolidayController extends Controller
{
    public function store(StoreHolidayRequest $request, Store $store): RedirectResponse
    {
        $store->holidays()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => '店休日を登録しました。']);

        return back();
    }

    public function destroy(Store $store, StoreHoliday $holiday): RedirectResponse
    {
        abort_unless($holiday->store_id === $store->id, 404);

        $holiday->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => '店休日を削除しました。']);

        return back();
    }
}
