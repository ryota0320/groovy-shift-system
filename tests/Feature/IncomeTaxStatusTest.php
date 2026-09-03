<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\IncomeTaxTableVersion;
use App\Models\User;
use App\Services\IncomeTaxSourceStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncomeTaxStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('initial-admin.email', 'ryota.i.0320@gmail.com');
        Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Asia/Tokyo'));
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_designated_development_admin_can_view_applied_and_retrieval_status(): void
    {
        $admin = User::factory()->create([
            'email' => 'ryota.i.0320@gmail.com',
            'role' => UserRole::Admin,
        ]);
        $this->createTableVersion(2026, 'current-hash');
        $this->createTableVersion(2027, 'next-hash');
        $this->app->make(IncomeTaxSourceStatusService::class)->record(2027, 'unchanged', [
            'source_page_url' => 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2027/01.htm',
            'source_url' => 'https://www.nta.go.jp/publication/pamph/gensen/zeigakuhyo2027/data/01-07.xlsx',
            'source_hash' => str_pad('next-hash', 64, '0'),
        ]);

        $this->actingAs($admin)
            ->get(route('income-tax-status.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/income-tax-status')
                ->where('auth.can_view_income_tax_status', true)
                ->where('current_tax_year', 2026)
                ->where('current_table.tax_year', 2026)
                ->where('retrieval.target_year', 2027)
                ->where('retrieval.status', 'applied')
                ->where('retrieval.raw_status', 'unchanged')
                ->has('table_versions', 2));
    }

    public function test_other_admin_and_employee_cannot_view_status_page_or_menu_permission(): void
    {
        $otherAdmin = User::factory()->create([
            'email' => 'other-admin@example.com',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($otherAdmin)
            ->get(route('income-tax-status.index'))
            ->assertForbidden();
        $this->actingAs($otherAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can_view_income_tax_status', false));

        $employee = User::factory()->employee()->create([
            'email' => 'ryota.i.0320@gmail.com',
        ]);
        $this->actingAs($employee)
            ->get(route('income-tax-status.index'))
            ->assertForbidden();
        $this->actingAs($employee)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can_view_income_tax_status', false));
    }

    public function test_new_source_and_fetch_error_are_exposed_without_being_marked_as_applied(): void
    {
        $admin = User::factory()->create([
            'email' => 'ryota.i.0320@gmail.com',
            'role' => UserRole::Admin,
        ]);
        $this->createTableVersion(2026, 'current-hash');
        $statusService = $this->app->make(IncomeTaxSourceStatusService::class);
        $statusService->record(2027, 'downloaded', [
            'source_hash' => 'new-official-hash',
        ]);

        $this->actingAs($admin)
            ->get(route('income-tax-status.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('retrieval.status', 'review_required'));

        $statusService->record(2027, 'error', [
            'error_message' => '公式Excelを検証できませんでした。',
        ]);
        $this->actingAs($admin)
            ->get(route('income-tax-status.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('retrieval.status', 'error')
                ->where('retrieval.error_message', '公式Excelを検証できませんでした。'));
    }

    private function createTableVersion(int $taxYear, string $hash): IncomeTaxTableVersion
    {
        return IncomeTaxTableVersion::query()->create([
            'tax_year' => $taxYear,
            'name' => "{$taxYear}年分 給与所得の源泉徴収税額表（月額表）",
            'source_url' => "https://www.nta.go.jp/{$taxYear}.xlsx",
            'source_hash' => str_pad($hash, 64, '0'),
            'imported_at' => now(),
        ]);
    }
}
