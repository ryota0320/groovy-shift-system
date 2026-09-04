<?php

namespace Tests\Unit;

use App\Models\Staff;
use Tests\TestCase;

/** Covers MST-003 through MST-006. */
class StaffEmploymentStatusTest extends TestCase
{
    public function test_staff_without_employment_dates_is_employed(): void
    {
        $staff = new Staff(['hired_at' => null, 'retired_at' => null]);

        $this->assertTrue($staff->isEmployedOn('2026-09-03'));
    }

    public function test_staff_is_not_employed_before_hire_date(): void
    {
        $staff = new Staff(['hired_at' => '2026-09-03', 'retired_at' => null]);

        $this->assertFalse($staff->isEmployedOn('2026-09-02'));
    }

    public function test_retirement_date_is_inclusive(): void
    {
        $staff = new Staff(['hired_at' => '2026-01-01', 'retired_at' => '2026-09-03']);

        $this->assertTrue($staff->isEmployedOn('2026-09-03'));
    }

    public function test_staff_is_retired_on_the_day_after_retirement(): void
    {
        $staff = new Staff(['hired_at' => '2026-01-01', 'retired_at' => '2026-09-03']);

        $this->assertFalse($staff->isEmployedOn('2026-09-04'));
    }
}
