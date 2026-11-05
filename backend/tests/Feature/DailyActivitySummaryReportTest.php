<?php

namespace Tests\Feature;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DailyActivitySummaryReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_report_requires_accounting_permission(): void
    {
        $this->signInOwner('daily-summary-denied')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');

        $this->getJson('/api/reports/daily/activity-summary?from=2026-11-01&to=2026-11-05')
            ->assertForbidden();
    }

    public function test_report_validates_dates_and_limits_the_range(): void
    {
        $this->signInOwner('daily-summary-dates')->assertOk();

        $this->getJson('/api/reports/daily/activity-summary?from=2026-11-06&to=2026-11-05')
            ->assertUnprocessable();
        $this->getJson('/api/reports/daily/activity-summary?from=2026-01-01&to=2026-11-05')
            ->assertUnprocessable();
        $this->getJson('/api/reports/daily/activity-summary?from=bad&to=2026-11-05')
            ->assertUnprocessable();
    }

    public function test_empty_report_uses_authenticated_context_and_exposes_no_internal_ids(): void
    {
        $this->signInOwner('daily-summary-empty')->assertOk();
        $context = app(TenantContext::class);
        $this->assertGreaterThan(0, $context->tenantId());
        $this->assertGreaterThan(0, $context->branchId());
        $this->assertGreaterThan(0, $context->warehouseId());

        $response = $this->getJson('/api/reports/daily/activity-summary?from=2026-11-04&to=2026-11-04')
            ->assertOk()
            ->assertJsonPath('from', '2026-11-04')
            ->assertJsonPath('to', '2026-11-04')
            ->assertJsonPath('totals.sale', '0.0000')
            ->assertJsonPath('totals.purchase', '0.0000')
            ->assertJsonCount(0, 'documents');

        $this->assertNoInternalIds($response->json());
    }
}
