<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class RemainingReportsAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cashier_cannot_read_new_accounting_or_stock_reports(): void
    {
        $owner = $this->signInOwner('remaining-reports-authz')->assertOk();
        $cashierRole = collect($this->getJson('/api/roles')->assertOk()->json())
            ->firstWhere('code', 'cashier');

        $this->postJson('/api/memberships', [
            'name' => 'No Reports Cashier',
            'username' => 'remaining-reports-no-access',
            'recovery_email' => 'remaining-reports-no-access@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashierRole['ulid']],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('remaining-reports-authz', 'remaining-reports-no-access')->assertOk();

        $this->getJson('/api/reports/products/gl-grid?from_date=2026-11-01&to_date=2026-11-08')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/products/stock-activity?from_date=2026-11-01&to_date=2026-11-08')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/products/dead-stock?days=90')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/products/stock-status')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/products/ledger?from_date=2026-11-01&to_date=2026-11-08')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/products/purchase-expiry-reference?report_date=2026-11-08&horizon_days=365')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/daily/profit-loss?from=2026-11-08&to=2026-11-08')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/sales/salesman-commission?from_date=2026-11-01&to_date=2026-11-08')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/accounts/outstanding-balances?as_of=2026-11-08&party_type=customer')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->getJson('/api/reports/accounts/inactive-customers?as_of=2026-11-08&min_days=1&max_days=30')
            ->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
    }
}
