<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StaffInitialImportRequest;
use App\Services\StaffInitialImportService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffInitialImportController extends Controller
{
    public function __construct(private StaffInitialImportService $imports) {}

    public function index(): Response
    {
        return Inertia::render('staffs/import');
    }

    public function store(StaffInitialImportRequest $request): RedirectResponse
    {
        $counts = $this->imports->import($request->file('file'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$counts['staffs']}名のスタッフをインポートしました。",
        ]);

        return to_route('staffs.index');
    }

    public function template(): StreamedResponse
    {
        $spreadsheet = $this->templateSpreadsheet();

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'staff-initial-import-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function templateSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('インポートデータ');
        $headers = array_values(StaffInitialImportService::HEADERS);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            ['PT001', '山田', '花子', 'はなちゃん', 'アルバイト', '2026-04-01', null, '46', '2026-04-01', null, 1300, '2026-04-01', null, 500, '非課税', '2026-04-01', null, '甲欄', 1, '2026-04-01', null],
            ['PT001', '山田', '花子', 'はなちゃん', 'アルバイト', '2026-04-01', null, 'オニカイ', '2026-04-01', null, 1300, '2026-04-01', null, 600, '課税', '2026-04-01', null, '甲欄', 1, '2026-04-01', null],
            [null, '佐藤', '太郎', null, '社員', '2026/04/01', null, '46', '2026/04/01', null, null, null, null, null, null, null, null, null, null, null, null],
        ], null, 'A2');

        $lastColumn = $sheet->getHighestColumn();
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}4");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '009AD8']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A1:{$lastColumn}4")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:{$lastColumn}4")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6E3E9');

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['B', 'C', 'D', 'H'], true) ? 18 : 14);
        }
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('入力方法');
        $guide->fromArray([
            ['スタッフ初期移行テンプレート'],
            ['1. 「インポートデータ」シートへ入力し、XLSXまたはCSVで保存してください。'],
            ['2. スタッフキーは任意です。空欄の場合は行ごとに別スタッフとして自動採番されます。'],
            ['3. 同一スタッフを複数行にする場合（複数店舗所属など）だけ、同じスタッフキーを入力してください。'],
            ['4. 氏と名は必須です。表示名は任意で、未入力の場合は画面にも氏名が表示されます。'],
            ['5. 店舗名は、システムへ登録済みの名称と完全一致させてください。'],
            ['6. 日付はYYYY-MM-DD、YYYY/MM/DD、Excel日付セルのいずれも使用できます。金額は0以上の整数、扶養人数の空欄は0人として登録されます。'],
            ['7. 雇用区分は「社員」「アルバイト」、課税区分は「課税」「非課税」、所得税区分は「甲欄」「乙欄」です。'],
            ['8. 1件でもエラーがある場合、全件を登録せず行番号付きでエラーを表示します。'],
            ['9. このインポートは新規登録専用です。同じファイルを再送するとスタッフが重複するため注意してください。'],
        ], null, 'A1');
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('009AD8');
        $guide->getColumnDimension('A')->setWidth(110);
        $guide->getStyle('A1:A10')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        foreach (range(2, 10) as $row) {
            $guide->getRowDimension($row)->setRowHeight(30);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
