<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('income-tax:fetch-next-year')
    ->dailyAt('06:10')
    ->timezone('Asia/Tokyo')
    ->when(function (): bool {
        $today = now('Asia/Tokyo');

        return $today->month > 8 || ($today->month === 8 && $today->day >= 20);
    })
    ->withoutOverlapping();
