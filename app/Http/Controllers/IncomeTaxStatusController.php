<?php

namespace App\Http\Controllers;

use App\Models\IncomeTaxTableVersion;
use App\Services\IncomeTaxSourceStatusService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IncomeTaxStatusController extends Controller
{
    public function __invoke(IncomeTaxSourceStatusService $statusService): Response
    {
        $today = now('Asia/Tokyo');
        $currentYear = $today->year;
        $targetYear = $today->copy()->addYear()->year;
        $versions = IncomeTaxTableVersion::query()
            ->withCount('rules')
            ->orderByDesc('tax_year')
            ->get();
        $currentVersion = $versions->firstWhere('tax_year', $currentYear);
        $targetVersion = $versions->firstWhere('tax_year', $targetYear);
        $sourceStatus = $statusService->read($targetYear);
        $rawStatus = is_string($sourceStatus['status'] ?? null)
            ? $sourceStatus['status']
            : 'not_checked';
        $sourceHash = is_string($sourceStatus['source_hash'] ?? null)
            ? $sourceStatus['source_hash']
            : null;

        return Inertia::render('settings/income-tax-status', [
            'current_tax_year' => $currentYear,
            'current_table' => $currentVersion === null ? null : $this->versionData($currentVersion),
            'retrieval' => [
                'target_year' => $targetYear,
                'status' => $this->displayStatus($rawStatus, $sourceHash, $targetVersion?->source_hash),
                'raw_status' => $rawStatus,
                'checked_at' => $this->formatDateTime($sourceStatus['checked_at'] ?? null),
                'source_page_url' => $sourceStatus['source_page_url'] ?? null,
                'source_url' => $sourceStatus['source_url'] ?? null,
                'source_hash' => $sourceHash,
                'error_message' => $sourceStatus['error_message'] ?? null,
            ],
            'table_versions' => $versions->map(fn (IncomeTaxTableVersion $version): array => $this->versionData($version))->values(),
            'schedule' => [
                'period' => '毎年8月20日〜12月31日',
                'time' => '毎日 06:10',
                'timezone' => 'Asia/Tokyo',
            ],
        ]);
    }

    /** @return array<string, int|string|null> */
    private function versionData(IncomeTaxTableVersion $version): array
    {
        return [
            'tax_year' => $version->tax_year,
            'name' => $version->name,
            'source_url' => $version->source_url,
            'source_hash' => $version->source_hash,
            'imported_at' => $version->imported_at->timezone('Asia/Tokyo')->format('Y/m/d H:i'),
            'rules_count' => $version->rules_count,
        ];
    }

    private function displayStatus(string $rawStatus, ?string $sourceHash, ?string $appliedHash): string
    {
        if ($rawStatus === 'error') {
            return 'error';
        }
        if ($rawStatus === 'not_published') {
            return 'not_published';
        }
        if ($sourceHash === null) {
            return 'not_checked';
        }

        return hash_equals((string) $appliedHash, $sourceHash) ? 'applied' : 'review_required';
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone('Asia/Tokyo')->format('Y/m/d H:i');
        } catch (Throwable) {
            return null;
        }
    }
}
