<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\ShiftPngService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\HeaderUtils;

class ShiftPngController extends Controller
{
    public function __invoke(Request $request, ShiftPngService $png): Response
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $store = Store::query()->findOrFail((int) $validated['store_id']);
        $month = Carbon::createFromFormat('!Y-m', $validated['month']);
        $safeStoreName = preg_replace('/[\\\\\/:*?"<>|]/u', '', $store->name) ?: '店舗';
        $filename = sprintf('%d年%02d月_%s_シフト.png', $month->year, $month->month, $safeStoreName);

        return response($png->render($store, $month), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, 'monthly-shift.png'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
