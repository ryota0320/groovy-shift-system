<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\AttendanceExcelService;
use App\Services\MonthlyAggregationService;
use App\Services\SelectedStoreService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AggregationExportController extends Controller
{
    public function __invoke(
        Request $request,
        MonthlyAggregationService $aggregations,
        AttendanceExcelService $excel,
        SelectedStoreService $selectedStores,
    ): BinaryFileResponse {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);
        $store = $selectedStores->resolveForPage($request, $validated['store_id'] ?? null);
        $report = $aggregations->build((int) $validated['year'], (int) $validated['month'], $store);
        $path = $excel->create($report, $store instanceof Store ? $store->name : '店舗未選択');
        $filename = sprintf('%d年%02d月_勤怠人件費集計.xlsx', $report->year, $report->month);

        return response()->download($path, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }
}
