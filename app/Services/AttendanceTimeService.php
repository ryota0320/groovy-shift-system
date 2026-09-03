<?php

namespace App\Services;

use App\Data\AttendanceTimeResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AttendanceTimeService
{
    public const BUSINESS_DAY_CUTOFF_MINUTES = 12 * 60;

    public const MAX_CLOCK_IN_OFFSET_MINUTES = self::BUSINESS_DAY_CUTOFF_MINUTES + (24 * 60) - 15;

    public const MAX_WORKING_MINUTES = 24 * 60;

    public const MAX_CLOCK_OUT_OFFSET_MINUTES = self::MAX_CLOCK_IN_OFFSET_MINUTES + self::MAX_WORKING_MINUTES - 15;

    public function calculate(
        string $workDate,
        int $clockInOffsetMinutes,
        int $clockOutOffsetMinutes,
    ): AttendanceTimeResult {
        $this->validateQuarterHour($clockInOffsetMinutes);
        $this->validateQuarterHour($clockOutOffsetMinutes);

        if (
            $clockInOffsetMinutes < self::BUSINESS_DAY_CUTOFF_MINUTES
            || $clockInOffsetMinutes > self::MAX_CLOCK_IN_OFFSET_MINUTES
        ) {
            throw new InvalidArgumentException('実出勤は営業日当日12:00から翌11:45までの15分単位で指定してください。');
        }

        if ($clockOutOffsetMinutes <= $clockInOffsetMinutes) {
            throw new InvalidArgumentException('退勤時刻は出勤時刻より後にしてください。');
        }

        if ($clockOutOffsetMinutes - $clockInOffsetMinutes >= self::MAX_WORKING_MINUTES) {
            throw new InvalidArgumentException('1回の勤務時間は24時間未満にしてください。');
        }

        $startOfWorkDate = CarbonImmutable::parse($workDate, config('app.timezone'))->startOfDay();
        $clockInAt = $startOfWorkDate->addMinutes($clockInOffsetMinutes);
        $clockOutAt = $startOfWorkDate->addMinutes($clockOutOffsetMinutes);
        $lateNightStart = $startOfWorkDate->setTime(22, 0);
        $lateNightEnd = $startOfWorkDate->addDay()->setTime(8, 0);
        $overlapStart = $clockInAt->greaterThan($lateNightStart) ? $clockInAt : $lateNightStart;
        $overlapEnd = $clockOutAt->lessThan($lateNightEnd) ? $clockOutAt : $lateNightEnd;

        return new AttendanceTimeResult(
            $clockInAt,
            $clockOutAt,
            $clockOutOffsetMinutes - $clockInOffsetMinutes,
            $overlapEnd->greaterThan($overlapStart)
                ? (int) $overlapStart->diffInMinutes($overlapEnd)
                : 0,
        );
    }

    private function validateQuarterHour(int $offset): void
    {
        if ($offset < 0 || $offset % 15 !== 0) {
            throw new InvalidArgumentException('出退勤は15分単位で指定してください。');
        }
    }
}
