<?php

use App\Enums\UserRole;
use App\Http\Controllers\Master\LateNightRateSettingController;
use App\Http\Controllers\Master\StaffController;
use App\Http\Controllers\Master\StaffHistoryController;
use App\Http\Controllers\Master\StaffUserController;
use App\Http\Controllers\Master\StoreController;
use App\Http\Controllers\Master\StoreHolidayController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? to_route('dashboard')
    : to_route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('role:'.UserRole::Admin->value.','.UserRole::Employee->value)->group(function () {
        Route::resource('stores', StoreController::class)->only(['index', 'store', 'edit', 'update']);
        Route::post('stores/{store}/holidays', [StoreHolidayController::class, 'store'])
            ->name('stores.holidays.store');
        Route::delete('stores/{store}/holidays/{holiday}', [StoreHolidayController::class, 'destroy'])
            ->name('stores.holidays.destroy');

        Route::resource('staffs', StaffController::class)->only(['index', 'create', 'store', 'edit', 'update']);
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
    });
});

require __DIR__.'/settings.php';
