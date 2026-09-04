<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\Payroll;
use App\Models\Staff;
use App\Services\PayrollPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use ZipArchive;

class PayrollStatementController extends Controller
{
    public function show(Request $request, Staff $staff, PayrollPdfService $pdf): Response
    {
        [$year, $month] = $this->validatedPeriod($request);
        $payroll = $this->validPayroll($staff, $year, $month);
        $filename = $pdf->filename($payroll);

        return response($pdf->render($payroll), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, 'payroll-statement.pdf'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function bulk(Request $request, PayrollPdfService $pdf): BinaryFileResponse
    {
        [$year, $month] = $this->validatedPeriod($request);
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $staffs = Staff::query()
            ->where('employment_type', EmploymentType::PartTime->value)
            ->where(fn (Builder $query) => $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $end))
            ->where(fn (Builder $query) => $query->whereNull('retired_at')->orWhereDate('retired_at', '>=', $start))
            ->whereHas('payrolls', fn (Builder $query) => $query
                ->where('year', $year)
                ->where('month', $month)
                ->where('gross_pay', '>', 0))
            ->inDisplayOrder()
            ->get();
        if ($staffs->isEmpty()) {
            throw ValidationException::withMessages(['payroll' => '支給額が1円以上の計算済み給与がありません。']);
        }
        $payrolls = $staffs->map(fn (Staff $staff): Payroll => $this->validPayroll($staff, $year, $month));
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('ZIP一時保存先を作成できません。');
        }
        $path = tempnam($directory, 'payroll-statements-');
        if ($path === false) {
            throw new RuntimeException('ZIP一時ファイルを作成できません。');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('給与明細ZIPを作成できません。');
        }
        try {
            /** @var array<string, true> $usedFilenames */
            $usedFilenames = [];
            foreach ($payrolls as $payroll) {
                $filename = $this->uniqueArchiveFilename(
                    $pdf->filename($payroll),
                    $payroll->staff_id,
                    $usedFilenames,
                );
                if (! $zip->addFromString($filename, $pdf->render($payroll))) {
                    throw new RuntimeException('給与明細をZIPへ追加できません。');
                }
            }
        } catch (\Throwable $throwable) {
            $zip->close();
            @unlink($path);
            throw $throwable;
        }
        if (! $zip->close()) {
            @unlink($path);
            throw new RuntimeException('給与明細ZIPを確定できません。');
        }

        return response()->download(
            $path,
            sprintf('%d年%02d月_給与明細一括.zip', $year, $month),
            ['Cache-Control' => 'private, no-store, max-age=0'],
        )->deleteFileAfterSend(true);
    }

    /** @return array{int, int} */
    private function validatedPeriod(Request $request): array
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return [(int) $validated['year'], (int) $validated['month']];
    }

    private function validPayroll(Staff $staff, int $year, int $month): Payroll
    {
        if ($staff->employment_type !== EmploymentType::PartTime) {
            throw ValidationException::withMessages(['payroll' => '社員は給与明細の対象外です。']);
        }
        $payroll = $staff->payrolls()->where('year', $year)->where('month', $month)->first();
        if (! $payroll instanceof Payroll) {
            throw ValidationException::withMessages(['payroll' => "{$staff->full_name}さんの給与が未計算です。"]);
        }
        if ($payroll->needs_recalculation) {
            throw ValidationException::withMessages(['payroll' => "{$staff->full_name}さんの給与を再計算してから明細を出力してください。"]);
        }
        if ($payroll->gross_pay <= 0) {
            throw ValidationException::withMessages(['payroll' => "{$staff->full_name}さんは支給額が0円のため給与明細を出力できません。"]);
        }

        return $payroll;
    }

    /**
     * @param  array<string, true>  $usedFilenames
     */
    private function uniqueArchiveFilename(string $filename, int $staffId, array &$usedFilenames): string
    {
        if (! isset($usedFilenames[$filename])) {
            $usedFilenames[$filename] = true;

            return $filename;
        }

        $basename = str_ends_with($filename, '.pdf')
            ? substr($filename, 0, -4)
            : $filename;
        $candidate = "{$basename}_スタッフID{$staffId}.pdf";
        $sequence = 2;

        while (isset($usedFilenames[$candidate])) {
            $candidate = "{$basename}_スタッフID{$staffId}_{$sequence}.pdf";
            $sequence++;
        }

        $usedFilenames[$candidate] = true;

        return $candidate;
    }
}
