<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\SelectedStoreService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SelectedStoreService $selectedStores): Response
    {
        $selectedStore = $selectedStores->current($request);
        $today = today();
        $weekday = ['日', '月', '火', '水', '木', '金', '土'][$today->dayOfWeek];
        $todayShiftCount = $selectedStore?->shifts()
            ->whereDate('shift_date', $today->toDateString())
            ->count() ?? 0;

        return Inertia::render('dashboard', [
            'stores' => Store::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'selected_store' => $selectedStore,
            'today' => $today->toDateString(),
            'today_label' => $today->format("Y年n月j日（{$weekday}）"),
            'today_shift_count' => $todayShiftCount,
        ]);
    }
}
