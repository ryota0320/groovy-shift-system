<?php

namespace App\Services;

use App\Data\AttendanceTimeResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AttendanceTimeService
{
    public const MAX_OFFSET_MINUTES = 34 * 60;

    public function calculate(
        string $workDate,
        int $clockInOffsetMinutes,
        int $clockOutOffsetMinutes,
    ): AttendanceTimeResult {
        $this->validateOffset($clockInOffsetMinutes, false);
        $this->validateOffset($clockOutOffsetMinutes, true);

        if ($clockOutOffsetMinutes <= $clockInOffsetMinutes) {
            throw new InvalidArgumentException('退勤時刻は出勤時刻より後にしてください。');
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
            $clockInOffsetMinutes === $clockOutOffsetMinutes
                ? 0
                : $clockOutOffsetMinutes - $clockInOffsetMinutes,
            $overlapEnd->greaterThan($overlapStart)
                ? (int) $overlapStart->diffInMinutes($overlapEnd)
                : 0,
        );
    }

    private function validateOffset(int $offset, bool $allowsUpperBoundary): void
    {
        $maximum = $allowsUpperBoundary
            ? self::MAX_OFFSET_MINUTES
            : self::MAX_OFFSET_MINUTES - 15;

        if ($offset < 0 || $offset > $maximum || $offset % 15 !== 0) {
            throw new InvalidArgumentException('出退勤は営業日当日から翌10:00までの15分単位で指定してください。');
        }
    }
}
