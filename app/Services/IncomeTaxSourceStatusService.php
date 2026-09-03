<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class IncomeTaxSourceStatusService
{
    /** @param array<string, mixed> $context */
    public function record(int $taxYear, string $status, array $context = []): void
    {
        $allowedStatuses = ['not_published', 'downloaded', 'unchanged', 'error'];
        if (! in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('所得税資料の取得状態が不正です。');
        }

        $metadata = [
            ...$context,
            'tax_year' => $taxYear,
            'status' => $status,
            'checked_at' => now('Asia/Tokyo')->toIso8601String(),
        ];
        $encoded = json_encode(
            $metadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (! Storage::disk('local')->put($this->path($taxYear), $encoded)) {
            throw new RuntimeException('所得税資料の取得状態を保存できませんでした。');
        }
    }

    /** @return array<string, mixed> */
    public function read(int $taxYear): array
    {
        $statusPath = $this->path($taxYear);
        if (Storage::disk('local')->exists($statusPath)) {
            $status = json_decode(Storage::disk('local')->get($statusPath), true);
            if (is_array($status) && ($status['tax_year'] ?? null) === $taxYear) {
                return $status;
            }
        }

        $latestPath = "income-tax/sources/{$taxYear}/latest.json";
        if (Storage::disk('local')->exists($latestPath)) {
            $latest = json_decode(Storage::disk('local')->get($latestPath), true);
            if (is_array($latest) && ($latest['tax_year'] ?? null) === $taxYear) {
                return [
                    ...$latest,
                    'status' => 'downloaded',
                    'checked_at' => $latest['downloaded_at'] ?? null,
                ];
            }
        }

        return [
            'tax_year' => $taxYear,
            'status' => 'not_checked',
            'checked_at' => null,
        ];
    }

    private function path(int $taxYear): string
    {
        return "income-tax/sources/{$taxYear}/status.json";
    }
}
