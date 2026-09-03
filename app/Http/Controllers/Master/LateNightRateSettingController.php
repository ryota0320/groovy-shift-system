<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\LateNightRateSettingRequest;
use App\Models\LateNightRateSetting;
use App\Services\EffectivePeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LateNightRateSettingController extends Controller
{
    public function __construct(private EffectivePeriodService $periods) {}

    public function index(): Response
    {
        return Inertia::render('settings/late-night-rates', [
            'rates' => LateNightRateSetting::query()
                ->latest('effective_from')
                ->get()
                ->map(fn (LateNightRateSetting $rate): array => [
                    'id' => $rate->id,
                    'amount_per_hour' => $rate->amount_per_hour,
                    'effective_from' => $rate->effective_from->toDateString(),
                    'effective_to' => $rate->effective_to?->toDateString(),
                ]),
        ]);
    }

    public function store(LateNightRateSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data): void {
            $this->periods->ensureNoOverlap(
                LateNightRateSetting::query(),
                $data['effective_from'],
                $data['effective_to'] ?? null,
            );
            LateNightRateSetting::query()->create($data);
        });

        return $this->success('深夜加算額を登録しました。');
    }

    public function update(
        LateNightRateSettingRequest $request,
        LateNightRateSetting $lateNightRate,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($lateNightRate, $data): void {
            $this->periods->ensureNoOverlap(
                LateNightRateSetting::query(),
                $data['effective_from'],
                $data['effective_to'] ?? null,
                $lateNightRate->id,
            );
            $lateNightRate->update($data);
        });

        return $this->success('深夜加算額を更新しました。');
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
