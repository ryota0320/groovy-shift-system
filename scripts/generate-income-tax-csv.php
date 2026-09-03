<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/generate-income-tax-csv.php YEAR SOURCE OUTPUT\n");
    exit(1);
}

$year = (int) $argv[1];
$source = $argv[2];
$output = $argv[3];
$config = match ($year) {
    2026 => ['low_max' => 105000, 'otsu_high' => 1710000],
    2027 => ['low_max' => 111000, 'otsu_high' => 1720000],
    default => throw new RuntimeException("Unsupported year: {$year}"),
};
$sheet = IOFactory::load($source)->getActiveSheet();
$regular = [];
$basePoints = [];

for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
    $min = $sheet->getCell("B{$row}")->getCalculatedValue();
    $max = $sheet->getCell("C{$row}")->getCalculatedValue();

    if (is_numeric($min) && is_numeric($max) && (int) $max <= 740000) {
        $regular[] = [
            'min' => (int) $min,
            'max' => (int) $max,
            'ko' => array_map(
                fn (string $column): int => (int) $sheet->getCell("{$column}{$row}")->getCalculatedValue(),
                range('D', 'K'),
            ),
            'otsu' => (int) $sheet->getCell("L{$row}")->getCalculatedValue(),
        ];
    }

    if (is_string($min) && preg_match('/^([0-9,]+)円$/u', trim($min), $matches)) {
        $amount = (int) str_replace(',', '', $matches[1]);
        if ($amount >= 740000 && $amount <= 3500000) {
            $basePoints[$amount] = [
                'ko' => array_map(
                    fn (string $column): int => (int) $sheet->getCell("{$column}{$row}")->getCalculatedValue(),
                    range('D', 'K'),
                ),
                'otsu' => is_numeric($sheet->getCell("L{$row}")->getCalculatedValue())
                    ? (int) $sheet->getCell("L{$row}")->getCalculatedValue()
                    : null,
            ];
        }
    }
}

ksort($basePoints);
$expectedPoints = [740000, 790000, 960000, $config['otsu_high'], 2130000, 2170000, 2210000, 2250000, 3500000];
if (array_keys($basePoints) !== $expectedPoints || $regular === []) {
    throw new RuntimeException('The official workbook shape did not match the expected monthly table.');
}

$rates = [
    740000 => [2042, 10000],
    790000 => [23483, 100000],
    960000 => [33693, 100000],
    $config['otsu_high'] => [4084, 10000],
    2130000 => [4084, 10000],
    2170000 => [4084, 10000],
    2210000 => [4084, 10000],
    2250000 => [4084, 10000],
    3500000 => [45945, 100000],
];
$rows = [];
$order = 0;
$append = function (
    string $category,
    ?int $dependent,
    int $min,
    ?int $max,
    string $type,
    ?int $fixed,
    ?array $parameters,
) use (&$rows, &$order): void {
    $rows[] = [$category, $dependent, $min, $max, $type, $fixed, $parameters === null ? null : json_encode($parameters), ++$order];
};

foreach (range(0, 7) as $dependent) {
    $append('ko', $dependent, 0, $config['low_max'], 'fixed', 0, null);
    foreach ($regular as $rule) {
        $append('ko', $dependent, $rule['min'], $rule['max'], 'fixed', $rule['ko'][$dependent], null);
    }
    $points = array_keys($basePoints);
    foreach ($points as $index => $point) {
        [$numerator, $denominator] = $rates[$point];
        $append('ko', $dependent, $point, $points[$index + 1] ?? null, 'marginal_round_10', null, [
            'base_amount' => $point,
            'base_tax' => $basePoints[$point]['ko'][$dependent],
            'rate_numerator' => $numerator,
            'rate_denominator' => $denominator,
        ]);
    }
}

$append('otsu', null, 0, $config['low_max'], 'percentage_floor', null, [
    'rate_numerator' => 3063,
    'rate_denominator' => 100000,
]);
foreach ($regular as $rule) {
    $append('otsu', null, $rule['min'], $rule['max'], 'fixed', $rule['otsu'], null);
}
$append('otsu', null, 740000, $config['otsu_high'], 'marginal_floor', null, [
    'base_amount' => 740000,
    'base_tax' => $basePoints[740000]['otsu'],
    'rate_numerator' => 4084,
    'rate_denominator' => 10000,
]);
$append('otsu', null, $config['otsu_high'], null, 'marginal_floor', null, [
    'base_amount' => $config['otsu_high'],
    'base_tax' => $basePoints[$config['otsu_high']]['otsu'],
    'rate_numerator' => 45945,
    'rate_denominator' => 100000,
]);

$directory = dirname($output);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    throw new RuntimeException("Cannot create {$directory}");
}
$handle = fopen($output, 'wb');
fputcsv($handle, ['tax_category', 'dependent_count', 'min_amount', 'max_amount', 'calculation_type', 'fixed_tax_amount', 'parameters', 'sort_order'], ',', '"', '');
foreach ($rows as $row) {
    fputcsv($handle, $row, ',', '"', '');
}
fclose($handle);

fwrite(STDOUT, "Generated {$output}: ".count($rows)." rules\n");
