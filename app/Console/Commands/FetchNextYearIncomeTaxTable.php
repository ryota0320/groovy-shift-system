<?php

namespace App\Console\Commands;

use App\Services\IncomeTaxSourceFetchService;
use App\Services\IncomeTaxSourceStatusService;
use Illuminate\Console\Command;
use Throwable;

class FetchNextYearIncomeTaxTable extends Command
{
    protected $signature = 'income-tax:fetch-next-year {year? : 取得対象年。省略時は翌年}';

    protected $description = '国税庁から翌年分の源泉徴収税額表（月額表）Excelを取得して安全性を検証する';

    public function handle(
        IncomeTaxSourceFetchService $fetcher,
        IncomeTaxSourceStatusService $statusService,
    ): int {
        $year = $this->argument('year');
        $taxYear = $year === null ? now('Asia/Tokyo')->addYear()->year : filter_var($year, FILTER_VALIDATE_INT);
        if (! is_int($taxYear) || $taxYear < 2000 || $taxYear > 2100) {
            $this->error('取得対象年は2000〜2100の整数で指定してください。');

            return self::INVALID;
        }

        try {
            $result = $fetcher->fetch($taxYear);
        } catch (Throwable $exception) {
            report($exception);
            try {
                $statusService->record($taxYear, 'error', [
                    'error_message' => $exception->getMessage(),
                ]);
            } catch (Throwable $statusException) {
                report($statusException);
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] === 'not_published') {
            $statusService->record($taxYear, 'not_published', [
                'source_page_url' => $result['source_page_url'],
            ]);
            $this->info("{$taxYear}年分の月額表はまだ公開されていません。");

            return self::SUCCESS;
        }
        if ($result['status'] === 'unchanged') {
            $statusService->record($taxYear, 'unchanged', $this->statusContext($result));
            $this->info("{$taxYear}年分の取得済み月額表に変更はありません。");

            return self::SUCCESS;
        }

        $statusService->record($taxYear, 'downloaded', $this->statusContext($result));
        $this->info("{$taxYear}年分の月額表を取得しました。");
        $this->line("保存先: {$result['storage_path']}");
        $this->line("SHA-256: {$result['source_hash']}");
        $this->warn('給与計算へ適用する前に、docs/income-tax-annual-update.mdの手順で開発管理者が確認してください。');

        return self::SUCCESS;
    }

    /**
     * @param  array{source_page_url: string, source_url: string|null, source_hash: string|null, storage_path: string|null}  $result
     * @return array<string, string|null>
     */
    private function statusContext(array $result): array
    {
        return [
            'source_page_url' => $result['source_page_url'],
            'source_url' => $result['source_url'],
            'source_hash' => $result['source_hash'],
            'storage_path' => $result['storage_path'],
        ];
    }
}
