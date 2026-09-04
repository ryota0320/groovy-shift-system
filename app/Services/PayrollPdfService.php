<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Payroll;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class PayrollPdfService
{
    public function render(Payroll $payroll): string
    {
        $this->loadFormalStaffName($payroll);
        $attendanceDays = AttendanceRecord::query()
            ->where('staff_id', $payroll->staff_id)
            ->whereYear('work_date', $payroll->year)
            ->whereMonth('work_date', $payroll->month)
            ->distinct()
            ->count('work_date');
        $fontDirectory = sys_get_temp_dir().'/groovy-shift-dompdf-fonts';
        if (! is_dir($fontDirectory) && ! mkdir($fontDirectory, 0775, true) && ! is_dir($fontDirectory)) {
            throw new RuntimeException('PDFフォントキャッシュを作成できません。');
        }
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $fontDirectory);
        $options->set('fontCache', $fontDirectory);
        $options->set('chroot', [base_path(), '/usr/share/fonts']);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $fontPath = $this->japaneseFontPath();
        if ($fontPath !== null) {
            foreach (['normal', 'bold'] as $weight) {
                $registered = $dompdf->getFontMetrics()->registerFont([
                    'family' => 'IPAGothic',
                    'style' => 'normal',
                    'weight' => $weight,
                ], 'file://'.$fontPath);
                if (! $registered) {
                    throw new RuntimeException('給与明細用の日本語フォントを登録できません。');
                }
            }
        }
        $html = view('pdf.payroll-statement', [
            'payroll' => $payroll,
            'attendanceDays' => $attendanceDays,
            'fontFamily' => $fontPath === null ? 'DejaVu Sans' : 'IPAGothic',
        ])->render();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(Payroll $payroll): string
    {
        $this->loadFormalStaffName($payroll);
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]/u', '', $payroll->staff->full_name) ?: 'スタッフ';

        return sprintf('%d年%02d月_%s_給与明細.pdf', $payroll->year, $payroll->month, $name);
    }

    private function loadFormalStaffName(Payroll $payroll): void
    {
        if (! $payroll->relationLoaded('staff')
            || ! array_key_exists('last_name', $payroll->staff->getAttributes())
            || ! array_key_exists('first_name', $payroll->staff->getAttributes())) {
            $payroll->load('staff:id,name,last_name,first_name');
        }
    }

    private function japaneseFontPath(): ?string
    {
        $paths = [
            '/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf',
            '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        if (app()->environment('testing')) {
            return null;
        }

        throw new RuntimeException('給与明細用の日本語フォントが見つかりません。Dockerイメージを再ビルドしてください。');
    }
}
