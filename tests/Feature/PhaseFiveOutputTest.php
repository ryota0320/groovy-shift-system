<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Models\AttendanceRecord;
use App\Models\Payroll;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffStoreAssignment;
use App\Models\StaffWageRate;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceExcelService;
use App\Services\MonthlyAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/** Covers AGG-001 through AGG-006, OUT-002 through OUT-007, OUT-009 and OUT-011. */
class PhaseFiveOutputTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregations_keep_raw_precision_until_each_final_display_unit(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create(['name' => '集計店舗']);
        $partTime = Staff::factory()->partTime()->create(['name' => '集計 花子', 'hired_at' => '2026-01-01']);
        $employee = Staff::factory()->employee()->create(['name' => '社員 太郎', 'hired_at' => '2026-01-01']);
        StaffWageRate::query()->create([
            'staff_id' => $partTime->id,
            'hourly_wage' => 1001,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $first = $this->attendance($partTime, $store, '2026-09-01', 15);
        $this->attendance($partTime, $store, '2026-09-02', 15);
        $this->attendance($employee, $store, '2026-09-01', 60);

        $report = app(MonthlyAggregationService::class)->build(2026, 9, $store);
        $partTimeRow = collect($report->storeRows)->firstWhere('staff_id', $partTime->id);
        $employeeRow = collect($report->storeRows)->firstWhere('staff_id', $employee->id);

        $this->assertSame(501, $partTimeRow['base_pay']);
        $this->assertSame(501, $partTimeRow['labor_cost']);
        $this->assertNull($employeeRow['base_pay']);
        $this->assertNull($employeeRow['labor_cost']);
        $this->assertSame([251, 251], collect($report->dailyGroups)->map(fn (array $day): int => $day['totals']['labor_cost'])->all());
        $this->assertSame(501, $report->storeTotals['labor_cost']);

        $this->actingAs($admin)
            ->get(route('aggregations.index', ['store_id' => $store->id, 'year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('aggregations/index')
                ->where('store_rows.1.base_pay', 501)
                ->where('store_totals.labor_cost', 501)
                ->where('daily_groups.0.totals.labor_cost', 251)
                ->where('cross_store_rows.0.employment_type', EmploymentType::Employee->value));

        $first->delete();
        $afterDelete = app(MonthlyAggregationService::class)->build(2026, 9, $store);
        $this->assertSame(251, $afterDelete->storeTotals['labor_cost']);
    }

    public function test_xlsx_uses_the_shared_report_and_contains_required_sheets(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create(['name' => '=XLSX店舗']);
        $staff = Staff::factory()->partTime()->create(['name' => '+出力 花子', 'hired_at' => '2026-01-01']);
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1200,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $this->attendance($staff, $store, '2026-09-01', 390);
        $report = app(MonthlyAggregationService::class)->build(2026, 9, $store);
        $path = app(AttendanceExcelService::class)->create($report, $store->name);

        try {
            $spreadsheet = IOFactory::load($path);
            $this->assertSame(['店舗別月次集計', '全店舗横断集計'], $spreadsheet->getSheetNames());
            $this->assertSame('6時間30分', $spreadsheet->getSheetByName('店舗別月次集計')?->getCell('D5')->getValue());
            $this->assertSame(7800, $spreadsheet->getSheetByName('店舗別月次集計')?->getCell('F5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $spreadsheet->getSheetByName('店舗別月次集計')?->getCell('B2')->getDataType());
            $this->assertSame('=XLSX店舗', $spreadsheet->getSheetByName('店舗別月次集計')?->getCell('B2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $spreadsheet->getSheetByName('店舗別月次集計')?->getCell('A5')->getDataType());
            $this->assertSame('+出力 花子', $spreadsheet->getSheetByName('店舗別月次集計')?->getCell('A5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $spreadsheet->getSheetByName('全店舗横断集計')?->getCell('D3')->getDataType());
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        $download = $this->actingAs($admin)
            ->get(route('aggregations.xlsx', ['store_id' => $store->id, 'year' => 2026, 'month' => 9]))
            ->assertOk();
        $this->assertUtf8DownloadName($download->headers->get('content-disposition'), '2026年09月_勤怠人件費集計.xlsx');
        @unlink($download->baseResponse->getFile()->getPathname());
    }

    public function test_individual_pdf_and_bulk_zip_use_only_current_saved_payrolls(): void
    {
        $admin = User::factory()->create();
        $first = Staff::factory()->partTime()->create([
            'name' => '明細 花子',
            'display_name' => 'はなちゃん',
            'hired_at' => '2026-01-01',
        ]);
        $second = Staff::factory()->partTime()->create(['name' => '明細 次郎', 'hired_at' => '2026-01-01']);
        $zero = Staff::factory()->partTime()->create(['name' => '支給 零', 'hired_at' => '2026-01-01']);
        Staff::factory()->partTime()->create(['name' => '未計算 三郎', 'hired_at' => '2026-01-01']);
        $firstPayroll = $this->payroll($first);
        $this->payroll($second);
        $this->payroll($zero)->update(['gross_pay' => 0, 'net_pay' => 0]);

        $statementHtml = view('pdf.payroll-statement', [
            'payroll' => $firstPayroll->load('staff'),
            'attendanceDays' => 0,
            'fontFamily' => 'sans-serif',
        ])->render();
        $this->assertStringContainsString('氏名：明細 花子 様', $statementHtml);
        $this->assertStringNotContainsString('はなちゃん', $statementHtml);

        $pdfResponse = $this->actingAs($admin)->get(route('payrolls.statement', [
            'staff' => $first,
            'year' => 2026,
            'month' => 9,
        ]));
        $pdfResponse->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertUtf8DownloadName($pdfResponse->headers->get('content-disposition'), '2026年09月_明細 花子_給与明細.pdf');
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());

        $this->from(route('payrolls.index'))
            ->actingAs($admin)
            ->get(route('payrolls.statement', ['staff' => $zero, 'year' => 2026, 'month' => 9]))
            ->assertRedirect(route('payrolls.index'))
            ->assertSessionHasErrors('payroll');

        $zipResponse = $this->actingAs($admin)->get(route('payrolls.statements.bulk', [
            'year' => 2026,
            'month' => 9,
        ]));
        $zipResponse->assertOk();
        $this->assertUtf8DownloadName($zipResponse->headers->get('content-disposition'), '2026年09月_給与明細一括.zip');
        $path = $zipResponse->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertSame(2, $zip->numFiles);
            $this->assertNotFalse($zip->locateName('2026年09月_明細 花子_給与明細.pdf'));
            $this->assertNotFalse($zip->locateName('2026年09月_明細 次郎_給与明細.pdf'));
            $this->assertFalse($zip->locateName('2026年09月_支給 零_給与明細.pdf'));
            $zip->close();
        } finally {
            @unlink($path);
        }

        $firstPayroll->update(['needs_recalculation' => true]);
        $this->from(route('payrolls.index'))
            ->actingAs($admin)
            ->get(route('payrolls.statement', ['staff' => $first, 'year' => 2026, 'month' => 9]))
            ->assertRedirect(route('payrolls.index'))
            ->assertSessionHasErrors('payroll');
        $this->from(route('payrolls.index'))
            ->actingAs($admin)
            ->get(route('payrolls.statements.bulk', ['year' => 2026, 'month' => 9]))
            ->assertRedirect(route('payrolls.index'))
            ->assertSessionHasErrors('payroll');
    }

    public function test_bulk_zip_keeps_every_statement_when_staff_names_are_duplicated(): void
    {
        $admin = User::factory()->create();
        $first = Staff::factory()->partTime()->create(['name' => '同姓 同名', 'hired_at' => '2026-01-01']);
        $second = Staff::factory()->partTime()->create(['name' => '同姓 同名', 'hired_at' => '2026-01-01']);
        $this->payroll($first);
        $this->payroll($second);

        $response = $this->actingAs($admin)->get(route('payrolls.statements.bulk', [
            'year' => 2026,
            'month' => 9,
        ]));
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertSame(2, $zip->numFiles);
            $this->assertNotFalse($zip->locateName('2026年09月_同姓 同名_給与明細.pdf'));
            $this->assertNotFalse($zip->locateName(
                "2026年09月_同姓 同名_給与明細_スタッフID{$second->id}.pdf",
            ));
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_monthly_shift_png_is_complete_and_output_routes_require_authentication(): void
    {
        $admin = User::factory()->create();
        $store = Store::factory()->create(['name' => 'PNG店舗']);
        $staff = Staff::factory()->partTime()->create(['name' => '画像 花子', 'hired_at' => '2026-01-01']);
        StaffStoreAssignment::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        Shift::query()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'shift_date' => '2026-09-01',
            'shift_type' => 'time',
            'start_time' => '19:00',
        ]);

        foreach ([
            route('aggregations.xlsx', ['store_id' => $store->id, 'year' => 2026, 'month' => 9]),
            route('shifts.monthly.png', ['store_id' => $store->id, 'month' => '2026-09']),
            route('payrolls.statement', ['staff' => $staff, 'year' => 2026, 'month' => 9]),
            route('payrolls.statements.bulk', ['year' => 2026, 'month' => 9]),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $response = $this->actingAs($admin)->get(route('shifts.monthly.png', [
            'store_id' => $store->id,
            'month' => '2026-09',
        ]));
        $response->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->assertUtf8DownloadName($response->headers->get('content-disposition'), '2026年09月_PNG店舗_シフト.png');
        $image = imagecreatefromstring($response->getContent());
        $this->assertNotFalse($image);
        $this->assertGreaterThan(2300, imagesx($image));
        $this->assertGreaterThan(180, imagesy($image));
        imagedestroy($image);
    }

    private function attendance(Staff $staff, Store $store, string $date, int $workingMinutes): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'staff_id' => $staff->id,
            'store_id' => $store->id,
            'work_date' => $date,
            'clock_in_at' => "{$date} 18:00:00",
            'clock_out_at' => Carbon::parse("{$date} 18:00:00")->addMinutes($workingMinutes),
            'working_minutes' => $workingMinutes,
            'late_night_minutes' => 0,
        ]);
    }

    private function payroll(Staff $staff): Payroll
    {
        return Payroll::query()->create([
            'staff_id' => $staff->id,
            'year' => 2026,
            'month' => 9,
            'payment_date' => '2026-10-10',
            'tax_year' => 2026,
            'working_minutes' => 390,
            'late_night_minutes' => 60,
            'base_pay' => 7800,
            'late_night_pay' => 300,
            'transportation_fee_total' => 500,
            'transportation_fee_taxable' => 0,
            'transportation_fee_non_taxable' => 500,
            'commission' => 1000,
            'gross_pay' => 9600,
            'taxable_pay' => 9100,
            'social_insurance_deduction' => 0,
            'tax_table_reference_amount' => 9100,
            'income_tax' => 100,
            'other_deductions' => 0,
            'total_deductions' => 100,
            'net_pay' => 9500,
            'needs_recalculation' => false,
            'calculated_at' => now(),
        ]);
    }

    private function assertUtf8DownloadName(?string $header, string $filename): void
    {
        $this->assertNotNull($header);
        $this->assertStringContainsString(rawurlencode($filename), $header);
    }
}
