<?php

namespace Database\Seeders;

use App\Models\IncomeTaxTableVersion;
use App\Models\Payroll;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IncomeTaxTableSeeder extends Seeder
{
    private const TABLES = [
        2026 => [
            'name' => '令和8年分 給与所得の源泉徴収税額表（月額表）',
            'source_url' => 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2026/data/01-07.xls',
            'source_hash' => '50aafa072df1bb6b6aa253a021f7cc246265c3f2393f9988ee01ad121bc4f310',
        ],
        2027 => [
            'name' => '令和9年分 給与所得の源泉徴収税額表（月額表）',
            'source_url' => 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2027/data/01-07.xlsx',
            'source_hash' => 'f2f331de1207ae0da6a3f416c7ad233de9411b0210a65e928c39527f1791fea5',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::TABLES as $year => $metadata) {
                $version = IncomeTaxTableVersion::query()->updateOrCreate(
                    ['tax_year' => $year],
                    [...$metadata, 'imported_at' => now()],
                );
                $version->rules()->delete();
                $rows = $this->readRules(database_path("data/income-tax/{$year}.csv"), $version->id);
                $this->validateRules($year, $rows);
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('income_tax_rules')->insert($chunk);
                }
            }

            Payroll::query()->update(['needs_recalculation' => true]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function readRules(string $path, int $versionId): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("所得税CSVを開けません: {$path}");
        }
        $expectedHeader = [
            'tax_category', 'dependent_count', 'min_amount', 'max_amount',
            'calculation_type', 'fixed_tax_amount', 'parameters', 'sort_order',
        ];
        $header = fgetcsv($handle, escape: '');
        if ($header !== $expectedHeader) {
            fclose($handle);

            throw new RuntimeException("所得税CSVのヘッダーが不正です: {$path}");
        }
        $header = $expectedHeader;
        $rows = [];
        $now = now();
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $rule = array_combine($header, $values);
            $rows[] = [
                'table_version_id' => $versionId,
                'tax_category' => $rule['tax_category'],
                'dependent_count' => $rule['dependent_count'] === '' ? null : (int) $rule['dependent_count'],
                'min_amount' => (int) $rule['min_amount'],
                'max_amount' => $rule['max_amount'] === '' ? null : (int) $rule['max_amount'],
                'calculation_type' => $rule['calculation_type'],
                'fixed_tax_amount' => $rule['fixed_tax_amount'] === '' ? null : (int) $rule['fixed_tax_amount'],
                'parameters' => $rule['parameters'] === '' ? null : $rule['parameters'],
                'sort_order' => (int) $rule['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function validateRules(int $year, array $rows): void
    {
        $expectedCount = match ($year) {
            2026 => 2162,
            2027 => 2135,
            default => throw new RuntimeException("未対応の所得税年度です: {$year}"),
        };
        if (count($rows) !== $expectedCount) {
            throw new RuntimeException("{$year}年分の所得税ルール件数が不正です。");
        }

        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $category = $row['tax_category'];
            $dependent = $row['dependent_count'];
            $type = $row['calculation_type'];
            if (! in_array($category, ['ko', 'otsu'], true)
                || ($category === 'ko' && (! is_int($dependent) || $dependent < 0 || $dependent > 7))
                || ($category === 'otsu' && $dependent !== null)
                || ! in_array($type, ['fixed', 'percentage_floor', 'marginal_floor', 'marginal_round_10'], true)
                || ! is_int($row['min_amount'])
                || ($row['max_amount'] !== null && (! is_int($row['max_amount']) || $row['max_amount'] <= $row['min_amount']))) {
                throw new RuntimeException("{$year}年分の所得税ルール形式が不正です。");
            }
            if (($type === 'fixed') !== ($row['fixed_tax_amount'] !== null)) {
                throw new RuntimeException("{$year}年分の所得税固定税額ルールが不正です。");
            }
            if ($type !== 'fixed') {
                $parameters = json_decode((string) $row['parameters'], true);
                if (! is_array($parameters)
                    || ! isset($parameters['rate_numerator'], $parameters['rate_denominator'])
                    || ! is_int($parameters['rate_numerator'])
                    || ! is_int($parameters['rate_denominator'])
                    || $parameters['rate_numerator'] < 0
                    || $parameters['rate_denominator'] < 1) {
                    throw new RuntimeException("{$year}年分の所得税算式パラメータが不正です。");
                }
            }

            $groups[$category.':'.($dependent ?? 'null')][] = $row;
        }

        $expectedGroups = ['otsu:null'];
        foreach (range(0, 7) as $dependent) {
            $expectedGroups[] = "ko:{$dependent}";
        }
        if (array_diff(array_keys($groups), $expectedGroups) !== []
            || array_diff($expectedGroups, array_keys($groups)) !== []) {
            throw new RuntimeException("{$year}年分の所得税区分・扶養列が不正です。");
        }

        foreach ($groups as $rules) {
            usort($rules, fn (array $left, array $right): int => $left['min_amount'] <=> $right['min_amount']);
            if ($rules[0]['min_amount'] !== 0 || $rules[array_key_last($rules)]['max_amount'] !== null) {
                throw new RuntimeException("{$year}年分の所得税ルールが全金額範囲を網羅していません。");
            }
            foreach ($rules as $index => $rule) {
                if (isset($rules[$index + 1]) && $rule['max_amount'] !== $rules[$index + 1]['min_amount']) {
                    throw new RuntimeException("{$year}年分の所得税ルールに重複または欠落があります。");
                }
            }
        }
    }
}
