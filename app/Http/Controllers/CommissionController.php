<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Http\Requests\CommissionRequest;
use App\Models\Commission;
use App\Models\Staff;
use App\Services\PayrollRecalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CommissionController extends Controller
{
    public function __construct(private PayrollRecalculationService $payrolls) {}

    public function update(CommissionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $staff = Staff::query()->findOrFail((int) $data['staff_id']);
        $this->assertPartTime($staff);

        DB::transaction(function () use ($data, $staff): void {
            Commission::query()->updateOrCreate(
                ['staff_id' => $staff->id, 'year' => $data['year'], 'month' => $data['month']],
                ['amount' => $data['amount']],
            );
            $this->payrolls->markMonth($staff, sprintf('%04d-%02d-01', $data['year'], $data['month']));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => '歩合を保存しました。給与を再計算してください。']);

        return back();
    }

    public function destroy(Staff $staff, int $year, int $month): RedirectResponse
    {
        $this->assertPartTime($staff);
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period' => '給与対象年月が不正です。']);
        }
        DB::transaction(function () use ($staff, $year, $month): void {
            Commission::query()
                ->where('staff_id', $staff->id)
                ->where('year', $year)
                ->where('month', $month)
                ->delete();
            $this->payrolls->markMonth($staff, sprintf('%04d-%02d-01', $year, $month));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => '歩合を削除しました。給与を再計算してください。']);

        return back();
    }

    private function assertPartTime(Staff $staff): void
    {
        if ($staff->employment_type !== EmploymentType::PartTime) {
            throw ValidationException::withMessages(['staff_id' => '社員には歩合を登録できません。']);
        }
    }
}
