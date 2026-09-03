<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class IncomeTaxSourceFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_year_monthly_table_is_downloaded_validated_and_kept_idempotently(): void
    {
        Storage::fake('local');
        $workbook = $this->monthlyTableWorkbook();
        $pageUrl = 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/01.htm';
        $sourceUrl = 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/data/01-07.xlsx';
        Http::fake([
            $pageUrl => Http::response('<a href="data/01-07.xlsx">給与所得の源泉徴収税額表（月額表）</a>'),
            $sourceUrl => Http::response($workbook, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]),
        ]);

        $hash = hash('sha256', $workbook);
        $sourcePath = "income-tax/sources/2028/{$hash}.xlsx";
        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('2028年分の月額表を取得しました。')
            ->expectsOutput("保存先: {$sourcePath}")
            ->assertSuccessful();

        Storage::disk('local')->assertExists($sourcePath);
        Storage::disk('local')->assertExists("income-tax/sources/2028/{$hash}.json");
        Storage::disk('local')->assertExists('income-tax/sources/2028/latest.json');
        Storage::disk('local')->assertExists('income-tax/sources/2028/status.json');
        $metadata = json_decode(
            Storage::disk('local')->get('income-tax/sources/2028/latest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('downloaded_requires_developer_review', $metadata['status']);
        $this->assertSame($sourceUrl, $metadata['source_url']);
        $this->assertSame($hash, $metadata['source_hash']);

        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('2028年分の取得済み月額表に変更はありません。')
            ->assertSuccessful();
        $this->assertStoredStatus('unchanged');
        Storage::disk('local')->assertExists($sourcePath);

        Storage::disk('local')->delete('income-tax/sources/2028/latest.json');
        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('2028年分の月額表を取得しました。')
            ->assertSuccessful();
        Storage::disk('local')->assertExists('income-tax/sources/2028/latest.json');
        $this->assertStoredStatus('downloaded');
    }

    public function test_not_yet_published_table_is_a_safe_no_op(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/01.htm' => Http::response('', 404),
        ]);

        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('2028年分の月額表はまだ公開されていません。')
            ->assertSuccessful();

        $this->assertStoredStatus('not_published');
        $this->assertSame(
            ['income-tax/sources/2028/status.json'],
            Storage::disk('local')->allFiles('income-tax/sources/2028'),
        );
    }

    public function test_shift_jis_official_page_can_be_parsed(): void
    {
        Storage::fake('local');
        $workbook = $this->monthlyTableWorkbook();
        $pageUrl = 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/01.htm';
        $sourceUrl = 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/data/01-07.xlsx';
        $html = mb_convert_encoding(
            '<html><body>令和10年分<a href="/publication/pamph/gensen/zeigakuhyo2028/data/01-07.xlsx">月額表</a></body></html>',
            'SJIS-win',
            'UTF-8',
        );
        Http::fake([
            $pageUrl => Http::response($html),
            $sourceUrl => Http::response($workbook),
        ]);

        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('2028年分の月額表を取得しました。')
            ->assertSuccessful();

        Storage::disk('local')->assertExists('income-tax/sources/2028/latest.json');
        $this->assertStoredStatus('downloaded');
    }

    public function test_published_page_without_expected_workbook_link_requires_review(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/01.htm' => Http::response(
                '<html><body>公開ページの構造が変わりました</body></html>',
            ),
        ]);

        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('国税庁の税額表ページに月額表Excelのリンクを確認できませんでした。ページ構成の変更を確認してください。')
            ->assertExitCode(Command::FAILURE);

        $this->assertStoredStatus('error');
        Storage::disk('local')->assertMissing('income-tax/sources/2028/latest.json');
    }

    public function test_non_nta_download_link_is_rejected(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/01.htm' => Http::response(
                '<a href="https://example.com/publication/pamph/gensen/zeigakuhyo2028/data/01-07.xlsx">月額表</a>',
            ),
        ]);

        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->expectsOutput('国税庁以外または想定外の場所を指すExcelリンクを拒否しました。')
            ->assertExitCode(Command::FAILURE);

        $this->assertStoredStatus('error');
        Storage::disk('local')->assertMissing('income-tax/sources/2028/latest.json');
    }

    public function test_invalid_workbook_is_never_saved(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/01.htm' => Http::response(
                '<a href="data/01-07.xlsx">月額表</a>',
            ),
            'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2028/data/01-07.xlsx' => Http::response('not an excel file'),
        ]);

        $this->artisan('income-tax:fetch-next-year', ['year' => 2028])
            ->assertExitCode(Command::FAILURE);

        $this->assertStoredStatus('error');
        Storage::disk('local')->assertMissing('income-tax/sources/2028/latest.json');
    }

    public function test_automatic_check_runs_daily_only_from_august_twentieth_through_december(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'income-tax:fetch-next-year'));
        $this->assertNotNull($event);
        $this->assertSame('10 6 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-19 06:10:00', 'Asia/Tokyo'));
            $this->assertFalse($event->filtersPass($this->app));
            Carbon::setTestNow(Carbon::parse('2026-08-20 06:10:00', 'Asia/Tokyo'));
            $this->assertTrue($event->filtersPass($this->app));
            Carbon::setTestNow(Carbon::parse('2026-12-31 06:10:00', 'Asia/Tokyo'));
            $this->assertTrue($event->filtersPass($this->app));
            Carbon::setTestNow(Carbon::parse('2027-01-01 06:10:00', 'Asia/Tokyo'));
            $this->assertFalse($event->filtersPass($this->app));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function monthlyTableWorkbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        for ($row = 1; $row <= 120; $row++) {
            $sheet->setCellValue("B{$row}", ($row - 1) * 1000);
            $sheet->setCellValue("C{$row}", $row * 1000);
            foreach (range('D', 'L') as $column) {
                $sheet->setCellValue("{$column}{$row}", $row);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'income-tax-test-');
        if ($path === false) {
            $this->fail('テスト用Excelの一時ファイルを作成できませんでした。');
        }
        (new Xlsx($spreadsheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);
        if ($contents === false) {
            $this->fail('テスト用Excelを読み込めませんでした。');
        }

        return $contents;
    }

    private function assertStoredStatus(string $expected): void
    {
        $status = json_decode(
            Storage::disk('local')->get('income-tax/sources/2028/status.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame($expected, $status['status']);
        $this->assertSame(2028, $status['tax_year']);
        $this->assertNotEmpty($status['checked_at']);
    }
}
