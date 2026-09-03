<?php

namespace App\Http\Controllers\Master;

use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StaffIncomeTaxSettingRequest;
use App\Http\Requests\Master\StaffStoreAssignmentRequest;
use App\Http\Requests\Master\StaffTransportationFeeRequest;
use App\Http\Requests\Master\StaffWageRateRequest;
use App\Models\Staff;
use App\Models\StaffIncomeTaxSetting;
use App\Models\StaffStoreAssignment;
use App\Models\StaffStoreTransportationFee;
use App\Models\StaffWageRate;
use App\Services\EffectivePeriodService;
use App\Services\PayrollRecalculationService;
use App\Services\ShiftMasterDataGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StaffHistoryController extends Controller
{
    public function __construct(
        private EffectivePeriodService $periods,
        private ShiftMasterDataGuard $shiftGuard,
        private PayrollRecalculationService $payrolls,
    ) {}

    public function storeAssignment(
        StaffStoreAssignmentRequest $request,
        Staff $staff,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($staff, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffStoreAssignment::query()
                    ->where('staff_id', $staff->id)
                    ->where('store_id', $data['store_id']),
                $data['effective_from'],
                $data['effective_to'] ?? null,
            );
            $staff->storeAssignments()->create($data);
        });

        return $this->success('店舗所属を登録しました。');
    }

    public function updateAssignment(
        StaffStoreAssignmentRequest $request,
        Staff $staff,
        StaffStoreAssignment $assignment,
    ): RedirectResponse {
        $this->ensureBelongsToStaff($staff, $assignment->staff_id);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $assignment, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->shiftGuard->ensureAssignmentPeriodCoversShifts(
                $staff,
                $assignment,
                (int) $data['store_id'],
                $data['effective_from'],
                $data['effective_to'] ?? null,
            );
            $this->periods->ensureNoOverlap(
                StaffStoreAssignment::query()
                    ->where('staff_id', $staff->id)
                    ->where('store_id', $data['store_id']),
                $data['effective_from'],
                $data['effective_to'] ?? null,
                $assignment->id,
            );
            $assignment->update($data);
        });

        return $this->success('店舗所属を更新しました。');
    }

    public function storeWageRate(StaffWageRateRequest $request, Staff $staff): RedirectResponse
    {
        $this->ensurePartTime($staff);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffWageRate::query()->where('staff_id', $staff->id),
                $data['effective_from'],
                $data['effective_to'] ?? null,
            );
            $staff->wageRates()->create($data);
            $this->payrolls->markStaff($staff);
        });

        return $this->success('時給履歴を登録しました。');
    }

    public function updateWageRate(
        StaffWageRateRequest $request,
        Staff $staff,
        StaffWageRate $wageRate,
    ): RedirectResponse {
        $this->ensureBelongsToStaff($staff, $wageRate->staff_id);
        $this->ensurePartTime($staff);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $wageRate, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffWageRate::query()->where('staff_id', $staff->id),
                $data['effective_from'],
                $data['effective_to'] ?? null,
                $wageRate->id,
            );
            $wageRate->update($data);
            $this->payrolls->markStaff($staff);
        });

        return $this->success('時給履歴を更新しました。');
    }

    public function storeTransportationFee(
        StaffTransportationFeeRequest $request,
        Staff $staff,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($staff, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffStoreTransportationFee::query()
                    ->where('staff_id', $staff->id)
                    ->where('store_id', $data['store_id']),
                $data['effective_from'],
                $data['effective_to'] ?? null,
            );
            $staff->transportationFees()->create($data);
            $this->payrolls->markStaff($staff);
        });

        return $this->success('交通費履歴を登録しました。');
    }

    public function updateTransportationFee(
        StaffTransportationFeeRequest $request,
        Staff $staff,
        StaffStoreTransportationFee $transportationFee,
    ): RedirectResponse {
        $this->ensureBelongsToStaff($staff, $transportationFee->staff_id);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $transportationFee, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffStoreTransportationFee::query()
                    ->where('staff_id', $staff->id)
                    ->where('store_id', $data['store_id']),
                $data['effective_from'],
                $data['effective_to'] ?? null,
                $transportationFee->id,
            );
            $transportationFee->update($data);
            $this->payrolls->markStaff($staff);
        });

        return $this->success('交通費履歴を更新しました。');
    }

    public function storeIncomeTaxSetting(
        StaffIncomeTaxSettingRequest $request,
        Staff $staff,
    ): RedirectResponse {
        $this->ensurePartTime($staff);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffIncomeTaxSetting::query()->where('staff_id', $staff->id),
                $data['effective_from'],
                $data['effective_to'] ?? null,
            );
            $staff->incomeTaxSettings()->create($data);
            $this->payrolls->markStaff($staff);
        });

        return $this->success('所得税設定を登録しました。');
    }

    public function updateIncomeTaxSetting(
        StaffIncomeTaxSettingRequest $request,
        Staff $staff,
        StaffIncomeTaxSetting $incomeTaxSetting,
    ): RedirectResponse {
        $this->ensureBelongsToStaff($staff, $incomeTaxSetting->staff_id);
        $this->ensurePartTime($staff);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $incomeTaxSetting, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->periods->ensureNoOverlap(
                StaffIncomeTaxSetting::query()->where('staff_id', $staff->id),
                $data['effective_from'],
                $data['effective_to'] ?? null,
                $incomeTaxSetting->id,
            );
            $incomeTaxSetting->update($data);
            $this->payrolls->markStaff($staff);
        });

        return $this->success('所得税設定を更新しました。');
    }

    private function ensurePartTime(Staff $staff): void
    {
        if ($staff->employment_type !== EmploymentType::PartTime) {
            throw ValidationException::withMessages([
                'staff' => '時給・所得税設定はアルバイトだけに登録できます。',
            ]);
        }
    }

    private function ensureBelongsToStaff(Staff $staff, int $staffId): void
    {
        abort_unless($staff->id === $staffId, 404);
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
