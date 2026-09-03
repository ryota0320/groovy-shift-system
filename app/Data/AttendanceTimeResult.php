<?php

namespace App\Data;

use Carbon\CarbonImmutable;

class AttendanceTimeResult
{
    public function __construct(
        public CarbonImmutable $clockInAt,
        public CarbonImmutable $clockOutAt,
        public int $workingMinutes,
        public int $lateNightMinutes,
    ) {}
}
