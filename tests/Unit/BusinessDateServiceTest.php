<?php

namespace Tests\Unit;

use App\Services\BusinessDateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class BusinessDateServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_before_noon_belongs_to_the_previous_business_date(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-09-05 11:59:59', 'Asia/Tokyo'));

        $this->assertSame(
            '2026-09-04',
            app(BusinessDateService::class)->current()->toDateString(),
        );
    }

    public function test_noon_belongs_to_the_current_calendar_date(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'Asia/Tokyo'));

        $this->assertSame(
            '2026-09-05',
            app(BusinessDateService::class)->current()->toDateString(),
        );
    }
}
