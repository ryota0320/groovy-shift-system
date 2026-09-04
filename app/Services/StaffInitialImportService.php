<?php

namespace App\Services;

use App\Data\StaffInitialImportRecord;
use App\Enums\EmploymentType;
use App\Enums\IncomeTaxCategory;
use App\Enums\TransportationTaxType;
use App\Models\Staff;
use App\Models\Store;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class StaffInitialImportService
{
    /** @var array<string, string> */
    public const HEADERS = [
        'staff_key' => 'スタッフキー',
        'last_name' => '氏',
        'first_name' => '名',
        'display_name' => '表示名',
        'employment_type' => '雇用区分',
        'hired_at' => '入社日',
        'retired_at' => '退職日',
        'store_name' => '店舗名',
        'assignment_from' => '所属開始日',
        'assignment_to' => '所属終了日',
        'hourly_wage' => '時給',
        'wage_from' => '時給開始日',
        'wage_to' => '時給終了日',
        'transportation_amount' => '交通費日額',
        'transportation_tax_type' => '交通費課税区分',
        'transportation_from' => '交通費開始日',
        'transportation_to' => '交通費終了日',
        'income_tax_category' => '所得税区分',
        'dependent_count' => '扶養人数',
        'tax_from' => '税設定開始日',
        'tax_to' => '税設定終了日',
    ];

    private const MAX_ROWS = 2000;

    /**
     * @return array{staffs: int, assignments: int, wage_rates: int, transportation_fees: int, income_tax_settings: int}
     */
    public function import(UploadedFile $file): array
    {
        $records = $this->normalize($this->readRows($file));

        return DB::transaction(function () use ($records): array {
            $counts = [
                'staffs' => 0,
                'assignments' => 0,
                'wage_rates' => 0,
                'transportation_fees' => 0,
                'income_tax_settings' => 0,
            ];

            foreach ($records as $record) {
                $staff = Staff::query()->create($record->staff);
                $counts['staffs']++;

                foreach ($record->assignments as $assignment) {
                    $staff->storeAssignments()->create($this->withoutRow($assignment));
                    $counts['assignments']++;
                }

                foreach ($record->wageRates as $rate) {
                    $staff->wageRates()->create($this->withoutRow($rate));
                    $counts['wage_rates']++;
                }

                foreach ($record->transportationFees as $fee) {
                    $staff->transportationFees()->create($this->withoutRow($fee));
                    $counts['transportation_fees']++;
                }

                foreach ($record->incomeTaxSettings as $setting) {
                    $staff->incomeTaxSettings()->create($this->withoutRow($setting));
                    $counts['income_tax_settings']++;
                }
            }

            return $counts;
        });
    }

    /** @return list<array<mixed>> */
    private function readRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        try {
            $reader = IOFactory::createReaderForFile($path);

            if ($reader instanceof Csv) {
                $contents = file_get_contents($path);
                $encoding = is_string($contents)
                    ? mb_detect_encoding($contents, ['UTF-8', 'SJIS-win', 'CP932'], true)
                    : false;
                $reader->setDelimiter(',');
                $reader->setInputEncoding(
                    $encoding === 'UTF-8' || $encoding === false ? 'UTF-8' : 'CP932',
                );
                $reader->setFallbackEncoding('CP932');
            }

            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            // Keep raw Excel values so date cells are handled as serial numbers,
            // regardless of whether their display format uses slashes or dashes.
            $rows = $spreadsheet->getSheet(0)->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'ファイルを読み取れませんでした。破損していないCSVまたはXLSXか確認してください。',
            ]);
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<mixed>>  $rows
     * @return list<StaffInitialImportRecord>
     */
    private function normalize(array $rows): array
    {
        if (count($rows) < 2) {
            $this->fail(1, 'ヘッダー行と1件以上のデータ行が必要です。');
        }

        $headerIndexes = $this->headerIndexes($rows[0]);
        $requiredHeaders = ['last_name', 'first_name', 'employment_type'];

        foreach ($requiredHeaders as $requiredHeader) {
            if (! array_key_exists($requiredHeader, $headerIndexes)) {
                $this->fail(1, self::HEADERS[$requiredHeader].'列がありません。テンプレートを使用してください。');
            }
        }

        $dataRows = [];
        foreach (array_slice($rows, 1, null, true) as $sourceIndex => $row) {
            if (collect($row)->contains(fn (mixed $value): bool => $this->string($value) !== '')) {
                $dataRows[] = [
                    'row' => $row,
                    'row_number' => $sourceIndex + 1,
                ];
            }
        }

        if ($dataRows === []) {
            $this->fail(2, 'インポート対象のデータがありません。');
        }

        if (count($dataRows) > self::MAX_ROWS) {
            $this->fail(2, '一度にインポートできるのは'.self::MAX_ROWS.'行までです。');
        }

        $stores = Store::query()->get(['id', 'name'])->keyBy('name');
        $staffs = [];

        foreach ($dataRows as $dataRow) {
            $row = $dataRow['row'];
            $rowNumber = $dataRow['row_number'];
            $value = fn (string $key): mixed => isset($headerIndexes[$key])
                ? ($row[$headerIndexes[$key]] ?? null)
                : null;
            $providedStaffKey = $this->string($value('staff_key'));
            // Namespace internal keys so an explicitly entered value can never
            // collide with a row-based automatically assigned key.
            $staffKey = $providedStaffKey === ''
                ? "auto:{$rowNumber}"
                : "provided:{$providedStaffKey}";
            $lastName = $this->string($value('last_name'));
            $firstName = $this->string($value('first_name'));
            $displayName = $this->string($value('display_name'));
            $employmentType = $this->employmentType($value('employment_type'), $rowNumber);

            if ($lastName === '') {
                $this->fail($rowNumber, '氏は必須です。');
            }
            if ($firstName === '') {
                $this->fail($rowNumber, '名は必須です。');
            }
            if (mb_strlen($lastName) > 120 || mb_strlen($firstName) > 120) {
                $this->fail($rowNumber, '氏と名はそれぞれ120文字以内で入力してください。');
            }
            if (mb_strlen($displayName) > 255) {
                $this->fail($rowNumber, '表示名は255文字以内で入力してください。');
            }

            $hiredAt = $this->date($value('hired_at'), $rowNumber, '入社日');
            $retiredAt = $this->date($value('retired_at'), $rowNumber, '退職日');
            $this->ensurePeriod($hiredAt, $retiredAt, $rowNumber, '在籍期間');

            if (! isset($staffs[$staffKey])) {
                $staffs[$staffKey] = new StaffInitialImportRecord([
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'display_name' => $displayName === '' ? null : $displayName,
                    'employment_type' => $employmentType,
                    'hired_at' => $hiredAt,
                    'retired_at' => $retiredAt,
                ]);
            } else {
                $profile = &$staffs[$staffKey]->staff;
                if ($profile['last_name'] !== $lastName
                    || $profile['first_name'] !== $firstName
                    || $profile['display_name'] !== ($displayName === '' ? null : $displayName)
                    || $profile['employment_type'] !== $employmentType) {
                    $this->fail($rowNumber, "スタッフキー「{$providedStaffKey}」の氏名、表示名または雇用区分が他の行と一致しません。");
                }
                $this->mergeProfileDate($profile, 'hired_at', $hiredAt, $rowNumber, '入社日');
                $this->mergeProfileDate($profile, 'retired_at', $retiredAt, $rowNumber, '退職日');
                $this->ensurePeriod($profile['hired_at'], $profile['retired_at'], $rowNumber, '在籍期間');
                unset($profile);
            }

            $storeName = $this->string($value('store_name'));
            $store = $storeName === '' ? null : $stores->get($storeName);
            if ($storeName !== '' && $store === null) {
                $this->fail($rowNumber, "店舗「{$storeName}」が登録されていません。");
            }

            $assignmentFrom = $this->date($value('assignment_from'), $rowNumber, '所属開始日');
            $assignmentTo = $this->date($value('assignment_to'), $rowNumber, '所属終了日');
            if ($store !== null || $assignmentFrom !== null || $assignmentTo !== null) {
                if ($store === null || $assignmentFrom === null) {
                    $this->fail($rowNumber, '店舗を登録する行は店舗名と所属開始日が必須です。');
                }
                $this->ensurePeriod($assignmentFrom, $assignmentTo, $rowNumber, '店舗所属期間');
                $staffs[$staffKey]->assignments[] = [
                    'store_id' => $store->id,
                    'effective_from' => $assignmentFrom,
                    'effective_to' => $assignmentTo,
                    '_row' => $rowNumber,
                ];
            }

            $hourlyWage = $this->integer($value('hourly_wage'), $rowNumber, '時給');
            $wageFrom = $this->date($value('wage_from'), $rowNumber, '時給開始日');
            $wageTo = $this->date($value('wage_to'), $rowNumber, '時給終了日');
            if ($hourlyWage !== null || $wageFrom !== null || $wageTo !== null) {
                if ($employmentType !== EmploymentType::PartTime->value) {
                    $this->fail($rowNumber, '社員には時給履歴を登録できません。');
                }
                if ($hourlyWage === null || $wageFrom === null) {
                    $this->fail($rowNumber, '時給を登録する行は時給と時給開始日が必須です。');
                }
                $this->ensurePeriod($wageFrom, $wageTo, $rowNumber, '時給期間');
                $staffs[$staffKey]->wageRates[] = [
                    'hourly_wage' => $hourlyWage,
                    'effective_from' => $wageFrom,
                    'effective_to' => $wageTo,
                    '_row' => $rowNumber,
                ];
            }

            $transportAmount = $this->integer($value('transportation_amount'), $rowNumber, '交通費日額');
            $transportTaxTypeRaw = $this->string($value('transportation_tax_type'));
            $transportFrom = $this->date($value('transportation_from'), $rowNumber, '交通費開始日');
            $transportTo = $this->date($value('transportation_to'), $rowNumber, '交通費終了日');
            if ($transportAmount !== null || $transportTaxTypeRaw !== '' || $transportFrom !== null || $transportTo !== null) {
                if ($store === null || $transportAmount === null || $transportTaxTypeRaw === '' || $transportFrom === null) {
                    $this->fail($rowNumber, '交通費を登録する行は店舗名・交通費日額・課税区分・開始日が必須です。');
                }
                $this->ensurePeriod($transportFrom, $transportTo, $rowNumber, '交通費期間');
                $staffs[$staffKey]->transportationFees[] = [
                    'store_id' => $store->id,
                    'amount_per_day' => $transportAmount,
                    'tax_type' => $this->transportationTaxType($transportTaxTypeRaw, $rowNumber),
                    'effective_from' => $transportFrom,
                    'effective_to' => $transportTo,
                    '_row' => $rowNumber,
                ];
            }

            $taxCategoryRaw = $this->string($value('income_tax_category'));
            $dependentCount = $this->integer($value('dependent_count'), $rowNumber, '扶養人数');
            $taxFrom = $this->date($value('tax_from'), $rowNumber, '税設定開始日');
            $taxTo = $this->date($value('tax_to'), $rowNumber, '税設定終了日');
            if ($taxCategoryRaw !== '' || $dependentCount !== null || $taxFrom !== null || $taxTo !== null) {
                $dependentCount ??= 0;
                if ($employmentType !== EmploymentType::PartTime->value) {
                    $this->fail($rowNumber, '社員には所得税設定履歴を登録できません。');
                }
                if ($taxCategoryRaw === '' || $taxFrom === null) {
                    $this->fail($rowNumber, '所得税設定を登録する行は所得税区分と開始日が必須です。');
                }
                $this->ensurePeriod($taxFrom, $taxTo, $rowNumber, '所得税設定期間');
                $staffs[$staffKey]->incomeTaxSettings[] = [
                    'tax_category' => $this->incomeTaxCategory($taxCategoryRaw, $rowNumber),
                    'dependent_count' => $dependentCount,
                    'effective_from' => $taxFrom,
                    'effective_to' => $taxTo,
                    '_row' => $rowNumber,
                ];
            }
        }

        foreach ($staffs as &$staff) {
            $staff->assignments = $this->unique($staff->assignments);
            $staff->wageRates = $this->unique($staff->wageRates);
            $staff->transportationFees = $this->unique($staff->transportationFees);
            $staff->incomeTaxSettings = $this->unique($staff->incomeTaxSettings);

            $this->ensureNoOverlap($staff->assignments, ['store_id'], '店舗所属期間');
            $this->ensureNoOverlap($staff->wageRates, [], '時給期間');
            $this->ensureNoOverlap($staff->transportationFees, ['store_id'], '交通費期間');
            $this->ensureNoOverlap($staff->incomeTaxSettings, [], '所得税設定期間');
        }
        unset($staff);

        return array_values($staffs);
    }

    /**
     * @param  array<mixed>  $headers
     * @return array<string, int>
     */
    private function headerIndexes(array $headers): array
    {
        $aliases = array_merge(array_flip(self::HEADERS), array_combine(array_keys(self::HEADERS), array_keys(self::HEADERS)));
        $indexes = [];

        foreach ($headers as $index => $header) {
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $this->string($header)) ?? '';
            if (isset($aliases[$name])) {
                $indexes[$aliases[$name]] = $index;
            }
        }

        return $indexes;
    }

    private function employmentType(mixed $value, int $row): string
    {
        return match ($this->string($value)) {
            'employee', '社員' => EmploymentType::Employee->value,
            'part_time', 'アルバイト', 'パート' => EmploymentType::PartTime->value,
            default => $this->fail($row, '雇用区分は「社員」または「アルバイト」を指定してください。'),
        };
    }

    private function transportationTaxType(string $value, int $row): string
    {
        return match ($value) {
            'taxable', '課税' => TransportationTaxType::Taxable->value,
            'non_taxable', '非課税' => TransportationTaxType::NonTaxable->value,
            default => $this->fail($row, '交通費課税区分は「課税」または「非課税」を指定してください。'),
        };
    }

    private function incomeTaxCategory(string $value, int $row): string
    {
        return match ($value) {
            'ko', '甲', '甲欄' => IncomeTaxCategory::Ko->value,
            'otsu', '乙', '乙欄' => IncomeTaxCategory::Otsu->value,
            default => $this->fail($row, '所得税区分は「甲欄」または「乙欄」を指定してください。'),
        };
    }

    private function string(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function integer(mixed $value, int $row, string $label): ?int
    {
        $string = $this->string($value);
        if ($string === '') {
            return null;
        }

        $normalized = str_replace([',', '，'], '', mb_convert_kana($string, 'n'));
        if (! preg_match('/^\d+$/', $normalized)) {
            $this->fail($row, "{$label}は0以上の整数で入力してください。");
        }

        return (int) $normalized;
    }

    private function date(mixed $value, int $row, string $label): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $string = $this->string($value);
        if ($string === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 10000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {
                $this->fail($row, "{$label}の日付形式が正しくありません。");
            }
        }

        $normalized = mb_convert_kana($string, 'n');
        if (preg_match('/^(\d{4})([-\/])(\d{1,2})\2(\d{1,2})$/', $normalized, $matches)
            && checkdate((int) $matches[3], (int) $matches[4], (int) $matches[1])) {
            return sprintf('%04d-%02d-%02d', $matches[1], $matches[3], $matches[4]);
        }

        $this->fail($row, "{$label}はYYYY-MM-DDまたはYYYY/MM/DD形式で入力してください。");
    }

    private function ensurePeriod(?string $from, ?string $to, int $row, string $label): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            $this->fail($row, "{$label}の終了日は開始日以降にしてください。");
        }
    }

    /** @param array<string, string|null> $profile */
    private function mergeProfileDate(
        array &$profile,
        string $key,
        ?string $value,
        int $row,
        string $label,
    ): void {
        if ($value === null) {
            return;
        }
        if ($profile[$key] !== null && $profile[$key] !== $value) {
            $this->fail($row, "同じスタッフキーの{$label}が他の行と一致しません。");
        }
        $profile[$key] = $value;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function unique(array $records): array
    {
        $seen = [];
        $unique = [];

        foreach ($records as $record) {
            $signature = json_encode($this->withoutRow($record), JSON_THROW_ON_ERROR);
            if (! isset($seen[$signature])) {
                $seen[$signature] = true;
                $unique[] = $record;
            }
        }

        return $unique;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $groupKeys
     */
    private function ensureNoOverlap(array $records, array $groupKeys, string $label): void
    {
        $groups = [];
        foreach ($records as $record) {
            $key = implode(':', array_map(fn (string $field): string => (string) $record[$field], $groupKeys));
            $groups[$key][] = $record;
        }

        foreach ($groups as $group) {
            usort($group, fn (array $a, array $b): int => $a['effective_from'] <=> $b['effective_from']);
            $previous = null;
            foreach ($group as $record) {
                if ($previous !== null
                    && ($previous['effective_to'] === null || $record['effective_from'] <= $previous['effective_to'])) {
                    $this->fail((int) $record['_row'], "{$label}が他の行と重複しています。");
                }
                $previous = $record;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function withoutRow(array $record): array
    {
        unset($record['_row']);

        return $record;
    }

    private function fail(int $row, string $message): never
    {
        throw ValidationException::withMessages([
            'file' => "{$row}行目: {$message}",
        ]);
    }
}
