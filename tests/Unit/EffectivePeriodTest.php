<?php

namespace Tests\Unit;

use App\Exceptions\MissingEffectiveSettingException;
use App\Models\Staff;
use App\Models\StaffWageRate;
use App\Services\EffectivePeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers MST-012 and MST-013. */
class EffectivePeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_and_end_dates_are_both_inclusive(): void
    {
        $staff = Staff::factory()->partTime()->create();
        $rate = StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1200,
            'effective_from' => '2026-04-01',
            'effective_to' => '2026-06-30',
        ]);
        $service = app(EffectivePeriodService::class);

        $this->assertTrue($rate->isEffectiveOn('2026-04-01'));
        $this->assertTrue($rate->isEffectiveOn('2026-06-30'));
        $this->assertSame(
            $rate->id,
            $service->resolve(
                StaffWageRate::query()->where('staff_id', $staff->id),
                '2026-04-01',
                '時給',
            )->id,
        );
        $this->assertSame(
            $rate->id,
            $service->resolve(
                StaffWageRate::query()->where('staff_id', $staff->id),
                '2026-06-30',
                '時給',
            )->id,
        );
    }

    public function test_a_gap_in_setting_periods_raises_an_explicit_error(): void
    {
        $staff = Staff::factory()->partTime()->create();
        StaffWageRate::query()->create([
            'staff_id' => $staff->id,
            'hourly_wage' => 1200,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-03-31',
        ]);

        $this->expectException(MissingEffectiveSettingException::class);
        $this->expectExceptionMessage('2026-04-01に有効な時給がありません。');

        app(EffectivePeriodService::class)->resolve(
            StaffWageRate::query()->where('staff_id', $staff->id),
            '2026-04-01',
            '時給',
        );
    }
}
