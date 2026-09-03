<?php

use App\Enums\UserRole;
use App\Http\Controllers\AggregationController;
use App\Http\Controllers\AggregationExportController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncomeTaxStatusController;
use App\Http\Controllers\Master\LateNightRateSettingController;
use App\Http\Controllers\Master\StaffController;
use App\Http\Controllers\Master\StaffHistoryController;
use App\Http\Controllers\Master\StaffInitialImportController;
use App\Http\Controllers\Master\StaffUserController;
use App\Http\Controllers\Master\StoreController;
use App\Http\Controllers\Master\StoreHolidayController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayrollStatementController;
use App\Http\Controllers\SelectedStoreController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftPngController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? to_route('dashboard')
    : to_route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('settings/income-tax-status', IncomeTaxStatusController::class)
        ->middleware('development-admin')
        ->name('income-tax-status.index');

    Route::middleware('role:'.UserRole::Admin->value.','.UserRole::Employee->value)->group(function () {
        Route::resource('stores', StoreController::class)->only(['index', 'store', 'edit', 'update']);
        Route::post('stores/{store}/holidays', [StoreHolidayController::class, 'store'])
            ->name('stores.holidays.store');
        Route::delete('stores/{store}/holidays/{holiday}', [StoreHolidayController::class, 'destroy'])
            ->name('stores.holidays.destroy');

        Route::resource('staffs', StaffController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::get('staffs-import', [StaffInitialImportController::class, 'index'])
            ->name('staffs.import.index');
        Route::post('staffs-import', [StaffInitialImportController::class, 'store'])
            ->name('staffs.import.store');
        Route::get('staffs-import/template', [StaffInitialImportController::class, 'template'])
            ->name('staffs.import.template');
        Route::post('staffs/{staff}/account', [StaffUserController::class, 'store'])
            ->name('staffs.account.store');
        Route::delete('staffs/{staff}/account', [StaffUserController::class, 'destroy'])
            ->name('staffs.account.destroy');

        Route::post('staffs/{staff}/assignments', [StaffHistoryController::class, 'storeAssignment'])
            ->name('staffs.assignments.store');
        Route::put('staffs/{staff}/assignments/{assignment}', [StaffHistoryController::class, 'updateAssignment'])
            ->name('staffs.assignments.update');
        Route::post('staffs/{staff}/wage-rates', [StaffHistoryController::class, 'storeWageRate'])
            ->name('staffs.wage-rates.store');
        Route::put('staffs/{staff}/wage-rates/{wageRate}', [StaffHistoryController::class, 'updateWageRate'])
            ->name('staffs.wage-rates.update');
        Route::post('staffs/{staff}/transportation-fees', [StaffHistoryController::class, 'storeTransportationFee'])
            ->name('staffs.transportation-fees.store');
        Route::put('staffs/{staff}/transportation-fees/{transportationFee}', [StaffHistoryController::class, 'updateTransportationFee'])
            ->name('staffs.transportation-fees.update');
        Route::post('staffs/{staff}/income-tax-settings', [StaffHistoryController::class, 'storeIncomeTaxSetting'])
            ->name('staffs.income-tax-settings.store');
        Route::put('staffs/{staff}/income-tax-settings/{incomeTaxSetting}', [StaffHistoryController::class, 'updateIncomeTaxSetting'])
            ->name('staffs.income-tax-settings.update');

        Route::get('settings/late-night-rates', [LateNightRateSettingController::class, 'index'])
            ->name('late-night-rates.index');
        Route::post('settings/late-night-rates', [LateNightRateSettingController::class, 'store'])
            ->name('late-night-rates.store');
        Route::put('settings/late-night-rates/{lateNightRate}', [LateNightRateSettingController::class, 'update'])
            ->name('late-night-rates.update');

        Route::get('shifts/monthly', [ShiftController::class, 'monthly'])
            ->name('shifts.monthly');
        Route::get('shifts/monthly.png', ShiftPngController::class)
            ->name('shifts.monthly.png');
        Route::get('shifts/daily', [ShiftController::class, 'daily'])
            ->name('shifts.daily');
        Route::post('shifts', [ShiftController::class, 'store'])
            ->name('shifts.store');
        Route::put('shifts/cell', [ShiftController::class, 'saveCell'])
            ->name('shifts.cell.save');
        Route::put('shifts/monthly/order', [ShiftController::class, 'saveMonthlyOrder'])
            ->name('shifts.monthly.order.save');
        Route::post('shifts/monthly/staffs', [ShiftController::class, 'addMonthlyStaff'])
            ->name('shifts.monthly.staffs.store');
        Route::delete('shifts/monthly/staffs', [ShiftController::class, 'removeMonthlyStaff'])
            ->name('shifts.monthly.staffs.destroy');
        Route::put('shifts/daily', [ShiftController::class, 'saveDaily'])
            ->name('shifts.daily.save');

        Route::get('attendance/daily', [AttendanceController::class, 'daily'])
            ->name('attendance.daily');
        Route::put('attendance/daily', [AttendanceController::class, 'saveDaily'])
            ->name('attendance.daily.save');
        Route::delete('attendance/{attendanceRecord}', [AttendanceController::class, 'destroy'])
            ->name('attendance.destroy');

        Route::get('payrolls', [PayrollController::class, 'index'])->name('payrolls.index');
        Route::get('aggregations', [AggregationController::class, 'index'])->name('aggregations.index');
        Route::get('aggregations.xlsx', AggregationExportController::class)->name('aggregations.xlsx');
        Route::post('payrolls/calculate-all', [PayrollController::class, 'calculateAll'])
            ->name('payrolls.calculate-all');
        Route::post('payrolls/{staff}/calculate', [PayrollController::class, 'calculate'])
            ->name('payrolls.calculate');
        Route::get('payrolls/{staff}/statement', [PayrollStatementController::class, 'show'])
            ->name('payrolls.statement');
        Route::get('payroll-statements.zip', [PayrollStatementController::class, 'bulk'])
            ->name('payrolls.statements.bulk');
        Route::put('commissions', [CommissionController::class, 'update'])
            ->name('commissions.update');
        Route::delete('commissions/{staff}/{year}/{month}', [CommissionController::class, 'destroy'])
            ->whereNumber(['year', 'month'])
            ->name('commissions.destroy');

        Route::put('selected-store', [SelectedStoreController::class, 'update'])
            ->name('selected-store.update');
    });
});

require __DIR__.'/settings.php';
