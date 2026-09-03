<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\MonthlyAggregationService;
use App\Services\SelectedStoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AggregationController extends Controller
{
    public function __construct(
        private MonthlyAggregationService $aggregations,
        private SelectedStoreService $selectedStores,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($validated['year'] ?? today()->year);
        $month = (int) ($validated['month'] ?? today()->month);
        $store = $this->selectedStores->resolveForPage($request, $validated['store_id'] ?? null);
        $report = $this->aggregations->build($year, $month, $store);
        $period = Carbon::create($year, $month, 1);

        return Inertia::render('aggregations/index', [
            ...$report->toArray(),
            'stores' => $report->stores,
            'selected_store' => $store instanceof Store ? [
                'id' => $store->id,
                'name' => $store->name,
            ] : null,
            'previous_period' => $period->copy()->subMonth()->format('Y-m'),
            'next_period' => $period->copy()->addMonth()->format('Y-m'),
        ]);
    }
}
