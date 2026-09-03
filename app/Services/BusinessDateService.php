<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class BusinessDateService
{
    public function current(): CarbonImmutable
    {
        $now = now(config('app.timezone'));

        if ($now->hour * 60 + $now->minute < AttendanceTimeService::BUSINESS_DAY_CUTOFF_MINUTES) {
            return $now->subDay()->startOfDay();
        }

        return $now->startOfDay();
    }
}
