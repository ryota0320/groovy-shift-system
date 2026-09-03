<?php

namespace Tests\Unit;

use App\Services\AttendanceTimeService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Covers ATT-002 through ATT-007, ATT-017, ATT-032 through ATT-035 and LNT-001 through LNT-008. */
class AttendanceTimeServiceTest extends TestCase
{
    #[DataProvider('timeCases')]
    public function test_working_and_late_night_minutes_follow_the_business_date(
        int $clockIn,
        int $clockOut,
        int $workingMinutes,
        int $lateNightMinutes,
    ): void {
        $result = (new AttendanceTimeService)->calculate('2026-09-04', $clockIn, $clockOut);

        $this->assertSame($workingMinutes, $result->workingMinutes);
        $this->assertSame($lateNightMinutes, $result->lateNightMinutes);
        $this->assertSame('2026-09-04', $result->clockInAt->toDateString());
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function timeCases(): iterable
    {
        yield '21:45 to 22:00' => [1305, 1320, 15, 0];
        yield '22:00 to 22:15' => [1320, 1335, 15, 15];
        yield '19:00 to 23:00' => [1140, 1380, 240, 60];
        yield '23:00 to next 02:00' => [1380, 1560, 180, 180];
        yield '22:00 to next 08:00' => [1320, 1920, 600, 600];
        yield '21:00 to next 09:00' => [1260, 1980, 720, 600];
        yield '22:00 to next 10:00' => [1320, 2040, 720, 600];
    }

    public function test_next_day_one_to_five_remains_on_the_previous_business_date(): void
    {
        $result = (new AttendanceTimeService)->calculate('2026-09-04', 1500, 1740);

        $this->assertSame('2026-09-05 01:00:00', $result->clockInAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 05:00:00', $result->clockOutAt->format('Y-m-d H:i:s'));
        $this->assertSame(240, $result->lateNightMinutes);
    }

    public function test_early_morning_times_cannot_be_registered_as_the_same_business_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AttendanceTimeService)->calculate('2026-09-05', 60, 300);
    }

    public function test_next_midnight_and_next_ten_are_valid_boundaries(): void
    {
        $midnight = (new AttendanceTimeService)->calculate('2026-09-04', 1380, 1440);
        $ten = (new AttendanceTimeService)->calculate('2026-09-04', 1980, 2040);

        $this->assertSame('2026-09-05 00:00:00', $midnight->clockOutAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 10:00:00', $ten->clockOutAt->format('Y-m-d H:i:s'));
    }

    public function test_preparation_before_opening_and_checkout_after_next_ten_are_valid(): void
    {
        $result = (new AttendanceTimeService)->calculate('2026-09-04', 900, 2220);

        $this->assertSame('2026-09-04 15:00:00', $result->clockInAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 13:00:00', $result->clockOutAt->format('Y-m-d H:i:s'));
        $this->assertSame(1320, $result->workingMinutes);
    }

    public function test_twenty_four_hour_shift_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AttendanceTimeService)->calculate('2026-09-04', 900, 2340);
    }

    public function test_non_quarter_hour_and_out_of_range_offsets_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AttendanceTimeService)->calculate('2026-09-04', 1141, 2040);
    }

    public function test_clock_out_must_be_after_clock_in(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AttendanceTimeService)->calculate('2026-09-04', 1200, 1200);
    }
}
