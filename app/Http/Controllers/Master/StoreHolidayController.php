<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreHolidayRequest;
use App\Models\Store;
use App\Models\StoreHoliday;
use App\Services\ShiftMasterDataGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StoreHolidayController extends Controller
{
    public function __construct(private ShiftMasterDataGuard $shiftGuard) {}

    public function store(StoreHolidayRequest $request, Store $store): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($store, $data): void {
            Store::query()->lockForUpdate()->findOrFail($store->id);
            $this->shiftGuard->ensureHolidayHasNoWorkShifts($store, $data['holiday_date']);
            $store->holidays()->create($data);
        });

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
