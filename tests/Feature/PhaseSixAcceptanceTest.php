<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers SEC-001 and SEC-002. */
class PhaseSixAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_baseline_security_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_guests_cannot_access_business_pages_or_generated_files(): void
    {
        $staff = Staff::factory()->create();
        $urls = [
            route('dashboard'),
            route('stores.index'),
            route('staffs.index'),
            route('shifts.monthly'),
            route('shifts.monthly.png'),
            route('shifts.daily'),
            route('attendance.daily'),
            route('payrolls.index'),
            route('payrolls.statement', $staff),
            route('payrolls.statements.bulk'),
            route('aggregations.index'),
            route('aggregations.xlsx'),
            route('late-night-rates.index'),
            route('income-tax-status.index'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }
}
