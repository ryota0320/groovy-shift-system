<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class IncomeTaxSourceFetchService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * @return array{status: 'downloaded'|'unchanged'|'not_published', tax_year: int, source_page_url: string, source_url: string|null, source_hash: string|null, storage_path: string|null}
     */
    public function fetch(int $taxYear): array
    {
        if ($taxYear < 2000 || $taxYear > 2100) {
            throw new RuntimeException('取得対象の所得税年度が不正です。');
        }

        $pageUrl = "https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo{$taxYear}/01.htm";
        $pageResponse = Http::withHeaders([
            'User-Agent' => 'GroovyShiftSystem-IncomeTaxFetcher/1.0',
        ])->connectTimeout(10)->timeout(30)->withoutRedirecting()->get($pageUrl);

        if ($pageResponse->status() === 404) {
            return $this->notPublished($taxYear, $pageUrl);
        }
        if (! $pageResponse->successful()) {
            throw new RuntimeException("国税庁の税額表ページを取得できませんでした（HTTP {$pageResponse->status()}）。");
        }

        $sourceUrl = $this->monthlyWorkbookUrl($pageUrl, $pageResponse->body());
        if ($sourceUrl === null) {
            throw new RuntimeException('国税庁の税額表ページに月額表Excelのリンクを確認できませんでした。ページ構成の変更を確認してください。');
        }
        $this->assertOfficialUrl($sourceUrl, $taxYear);

        $sourceResponse = Http::withHeaders([
            'User-Agent' => 'GroovyShiftSystem-IncomeTaxFetcher/1.0',
        ])->connectTimeout(10)->timeout(60)->withoutRedirecting()->get($sourceUrl);
        if (! $sourceResponse->successful()) {
            throw new RuntimeException("国税庁の月額表Excelを取得できませんでした（HTTP {$sourceResponse->status()}）。");
        }

        $contents = $sourceResponse->body();
        if ($contents === '' || strlen($contents) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('取得した月額表Excelのファイルサイズが不正です。');
        }
        $extension = strtolower((string) pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        $this->validateWorkbook($contents, $extension);

        $hash = hash('sha256', $contents);
        $directory = "income-tax/sources/{$taxYear}";
        $sourcePath = "{$directory}/{$hash}.{$extension}";
        $metadataPath = "{$directory}/{$hash}.json";
        $latestPath = "{$directory}/latest.json";
        $metadata = [
            'status' => 'downloaded_requires_developer_review',
            'tax_year' => $taxYear,
            'source_page_url' => $pageUrl,
            'source_url' => $sourceUrl,
            'source_hash' => $hash,
            'storage_path' => $sourcePath,
            'file_size' => strlen($contents),
            'downloaded_at' => now('Asia/Tokyo')->toIso8601String(),
        ];

        $latestMetadata = Storage::disk('local')->exists($latestPath)
            ? json_decode(Storage::disk('local')->get($latestPath), true)
            : null;
        if (Storage::disk('local')->exists($sourcePath)
            && Storage::disk('local')->exists($metadataPath)
            && is_array($latestMetadata)
            && ($latestMetadata['source_hash'] ?? null) === $hash) {
            return [
                'status' => 'unchanged',
                'tax_year' => $taxYear,
                'source_page_url' => $pageUrl,
                'source_url' => $sourceUrl,
                'source_hash' => $hash,
                'storage_path' => $sourcePath,
            ];
        }

        $encodedMetadata = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! Storage::disk('local')->put($sourcePath, $contents)
            || ! Storage::disk('local')->put($metadataPath, $encodedMetadata)
            || ! Storage::disk('local')->put($latestPath, $encodedMetadata)) {
            throw new RuntimeException('取得した所得税資料をローカルストレージへ保存できませんでした。');
        }

        Log::notice('翌年分の源泉徴収税額表を取得しました。開発管理者による取込確認が必要です。', $metadata);

        return [
            'status' => 'downloaded',
            'tax_year' => $taxYear,
            'source_page_url' => $pageUrl,
            'source_url' => $sourceUrl,
            'source_hash' => $hash,
            'storage_path' => $sourcePath,
        ];
    }

    /** @return array{status: 'not_published', tax_year: int, source_page_url: string, source_url: null, source_hash: null, storage_path: null} */
    private function notPublished(int $taxYear, string $pageUrl): array
    {
        return [
            'status' => 'not_published',
            'tax_year' => $taxYear,
            'source_page_url' => $pageUrl,
            'source_url' => null,
            'source_hash' => null,
            'storage_path' => null,
        ];
    }

    private function monthlyWorkbookUrl(string $pageUrl, string $html): ?string
    {
        // 国税庁の過去ページにはUTF-8以外のHTMLもあるため、ASCII部分だけを
        // 探すこの正規表現ではUTF-8モードを使用しない。
        $matchResult = preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $matches);
        if ($matchResult === false) {
            throw new RuntimeException('国税庁の税額表ページを解析できませんでした。');
        }
        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = (string) parse_url($href, PHP_URL_PATH);
            if (! preg_match('~(?:^|/)data/01-07\.xlsx?$~i', $path)) {
                continue;
            }

            if (preg_match('~^https://~i', $href)) {
                return $href;
            }
            if (str_starts_with($href, '/')) {
                return 'https://www.nta.go.jp'.$href;
            }

            if (str_contains($path, '..')) {
                throw new RuntimeException('相対パスに不正な税額表Excelリンクが含まれています。');
            }

            return rtrim(dirname($pageUrl), '/').'/'.(string) preg_replace('~^\./~', '', $href);
        }

        return null;
    }

    private function assertOfficialUrl(string $url, int $taxYear): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $expectedPrefix = "/publication/pamph/gensen/zeigakuhyo{$taxYear}/data/";
        if ($scheme !== 'https'
            || $host !== 'www.nta.go.jp'
            || ! str_starts_with($path, $expectedPrefix)
            || ! preg_match('~/01-07\.xlsx?$~i', $path)) {
            throw new RuntimeException('国税庁以外または想定外の場所を指すExcelリンクを拒否しました。');
        }
    }

    private function validateWorkbook(string $contents, string $extension): void
    {
        if (! in_array($extension, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('月額表のファイル形式がExcelではありません。');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'income-tax-source-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException('月額表Excelの一時ファイルを作成できませんでした。');
        }

        try {
            $type = IOFactory::identify($temporaryPath);
            if (! in_array($type, ['Xls', 'Xlsx'], true)) {
                throw new RuntimeException('取得ファイルをExcelとして認識できませんでした。');
            }
            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($temporaryPath)->getActiveSheet();
            $tableRows = 0;
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                if (is_numeric($sheet->getCell("B{$row}")->getValue())
                    && is_numeric($sheet->getCell("C{$row}")->getValue())
                    && is_numeric($sheet->getCell("D{$row}")->getValue())
                    && is_numeric($sheet->getCell("L{$row}")->getValue())) {
                    $tableRows++;
                }
            }
            if ($tableRows < 100) {
                throw new RuntimeException('取得Excelに月額表の甲欄・乙欄データを確認できませんでした。');
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('取得した月額表Excelを検証できませんでした。', previous: $exception);
        } finally {
            @unlink($temporaryPath);
        }
    }
}
