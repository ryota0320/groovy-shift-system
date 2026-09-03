<?php

namespace App\Services;

use App\Data\MonthlyAggregationReport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class AttendanceExcelService
{
    public function create(MonthlyAggregationReport $report, string $storeName): string
    {
        $spreadsheet = new Spreadsheet;
        $storeSheet = $spreadsheet->getActiveSheet();
        $storeSheet->setTitle('店舗別月次集計');
        $storeSheet->fromArray([
            ['対象年月', sprintf('%d年%02d月', $report->year, $report->month)],
            ['店舗', $storeName],
            [],
            ['スタッフ', '雇用区分', '出勤日数', '勤務時間', '深夜時間', '基本給相当', '深夜勤務手当', '交通費', '人件費'],
        ]);
        $rowNumber = 5;
        foreach ($report->storeRows as $row) {
            $storeSheet->fromArray([[
                $row['name'],
                $row['employment_type_label'],
                $row['attendance_days'],
                $this->minutes((int) $row['working_minutes']),
                $row['employment_type'] === 'part_time' ? $this->minutes((int) $row['late_night_minutes']) : '対象外',
                $row['base_pay'] ?? '対象外',
                $row['late_night_pay'] ?? '対象外',
                $row['transportation_fee'] ?? '対象外',
                $row['labor_cost'] ?? '対象外',
            ]], null, "A{$rowNumber}");
            $rowNumber++;
        }
        $storeSheet->fromArray([[
            '合計', '', $report->storeTotals['attendance_days'],
            $this->minutes($report->storeTotals['working_minutes']),
            $this->minutes($report->storeTotals['late_night_minutes']),
            $report->storeTotals['base_pay'],
            $report->storeTotals['late_night_pay'],
            $report->storeTotals['transportation_fee'],
            $report->storeTotals['labor_cost'],
        ]], null, "A{$rowNumber}");
        $this->styleSheet($storeSheet, 4, $rowNumber, 9);

        $crossSheet = $spreadsheet->createSheet();
        $crossSheet->setTitle('全店舗横断集計');
        $headers = ['スタッフ', '雇用区分', '出勤日数'];
        foreach ($report->stores as $store) {
            $headers[] = $store['name'].' 勤務時間';
        }
        array_push($headers, '総勤務時間', '深夜時間', '基本給', '深夜勤務手当', '交通費', '正式総支給額', '給与状態');
        $crossSheet->fromArray([
            ['対象年月', sprintf('%d年%02d月', $report->year, $report->month)],
            [],
            $headers,
        ]);
        $rowNumber = 4;
        foreach ($report->crossStoreRows as $row) {
            $values = [$row['name'], $row['employment_type_label'], $row['attendance_days']];
            foreach ($row['store_minutes'] as $storeMinutes) {
                $values[] = $this->minutes((int) $storeMinutes['working_minutes']);
            }
            $payroll = $row['payroll'];
            array_push(
                $values,
                $this->minutes((int) $row['working_minutes']),
                $this->minutes((int) $row['late_night_minutes']),
                $payroll['base_pay'] ?? '対象外',
                $payroll['late_night_pay'] ?? '対象外',
                $payroll['transportation_fee'] ?? '対象外',
                $payroll['gross_pay'] ?? '対象外',
                $row['employment_type'] === 'employee'
                    ? '対象外'
                    : ($payroll === null ? '未計算' : ($payroll['needs_recalculation'] ? '再計算が必要' : '計算済み')),
            );
            $crossSheet->fromArray([$values], null, "A{$rowNumber}");
            $rowNumber++;
        }
        $this->styleSheet($crossSheet, 3, max(3, $rowNumber - 1), count($headers));

        $directory = storage_path('app/private/exports');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('XLSX一時保存先を作成できません。');
        }
        $path = tempnam($directory, 'aggregation-');
        if ($path === false) {
            throw new RuntimeException('XLSX一時ファイルを作成できません。');
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function minutes(int $minutes): string
    {
        return sprintf('%d時間%02d分', intdiv($minutes, 60), $minutes % 60);
    }

    private function styleSheet(Worksheet $sheet, int $headerRow, int $lastRow, int $lastColumn): void
    {
        $lastColumnName = Coordinate::stringFromColumnIndex($lastColumn);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumnName}{$headerRow}");
        $sheet->getStyle("A{$headerRow}:{$lastColumnName}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '279FD3']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A{$headerRow}:{$lastColumnName}{$lastRow}")
            ->getBorders()->getAllBorders()->setBorderStyle('thin');
        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }
}
