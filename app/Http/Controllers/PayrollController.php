<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Models\Commission;
use App\Models\Payroll;
use App\Models\Staff;
use App\Services\PayrollCalculationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function __construct(private PayrollCalculationService $payrolls) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($validated['year'] ?? today()->year);
        $month = (int) ($validated['month'] ?? today()->month);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $staffs = Staff::query()
            ->where('employment_type', EmploymentType::PartTime->value)
            ->where(fn (Builder $query) => $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $end))
            ->where(fn (Builder $query) => $query->whereNull('retired_at')->orWhereDate('retired_at', '>=', $start))
            ->with([
                'payrolls' => fn ($query) => $query->where('year', $year)->where('month', $month),
                'commissions' => fn ($query) => $query->where('year', $year)->where('month', $month),
            ])
            ->inDisplayOrder()
            ->get()
            ->map(function (Staff $staff): array {
                $payroll = $staff->payrolls->first();
                $commission = $staff->commissions->first();

                return [
                    'staff_id' => $staff->id,
                    'name' => $staff->name,
                    'commission' => $commission instanceof Commission ? $commission->amount : 0,
                    'payroll' => $payroll instanceof Payroll ? $this->payrollPayload($payroll) : null,
                ];
            });

        return Inertia::render('payrolls/index', [
            'year' => $year,
            'month' => $month,
            'previous_period' => $start->copy()->subMonth()->format('Y-m'),
            'next_period' => $start->copy()->addMonth()->format('Y-m'),
            'staffs' => $staffs,
        ]);
    }

    public function calculate(Request $request, Staff $staff): RedirectResponse
    {
        [$year, $month] = $this->validatedPeriod($request);
        $this->payrolls->calculate($staff, $year, $month);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$staff->name}さんの給与を再計算しました。"]);

        return back();
    }

    public function calculateAll(Request $request): RedirectResponse
    {
        [$year, $month] = $this->validatedPeriod($request);
        $this->payrolls->calculateAll($year, $month);
        Inertia::flash('toast', ['type' => 'success', 'message' => '全アルバイトの給与を再計算しました。']);

        return back();
    }

    /** @return array{int, int} */
    private function validatedPeriod(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return [(int) $data['year'], (int) $data['month']];
    }

    /** @return array<string, mixed> */
    private function payrollPayload(Payroll $payroll): array
    {
        return collect($payroll->only([
            'id', 'working_minutes', 'late_night_minutes', 'base_pay', 'late_night_pay',
            'transportation_fee_total', 'transportation_fee_taxable',
            'transportation_fee_non_taxable', 'commission', 'gross_pay', 'taxable_pay',
            'social_insurance_deduction', 'tax_table_reference_amount', 'income_tax',
            'other_deductions', 'total_deductions', 'net_pay', 'needs_recalculation',
        ]))->merge([
            'payment_date' => $payroll->payment_date->toDateString(),
            'tax_year' => $payroll->tax_year,
            'calculated_at' => $payroll->calculated_at?->toIso8601String(),
        ])->all();
    }
}
