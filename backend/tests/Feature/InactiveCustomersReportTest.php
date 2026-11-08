<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class InactiveCustomersReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_never_sold_customer_is_optional_and_date_range_is_validated(): void
    {
        $this->signInOwner('inactive-customer-a')->assertOk();
        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'INACTIVE-001',
            'name' => 'Never Sold Test Customer',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated();

        $base = '/api/reports/accounts/inactive-customers?as_of=2026-11-08&min_days=1&max_days=30&q=Never%20Sold';
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 0);
        $found = $this->getJson($base.'&include_never=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ulid', $customer->json('ulid'))
            ->assertJsonPath('data.0.last_sale_date', null)
            ->assertJsonPath('data.0.days_inactive', null);
        $this->assertNoInternalIds($found->json());
        $this->getJson('/api/reports/accounts/inactive-customers?as_of=2026-11-08&min_days=31&max_days=1')
            ->assertUnprocessable();
    }

    public function test_inactive_customers_do_not_leak_across_tenants(): void
    {
        $this->signInOwner('inactive-customer-tenant-a')->assertOk();
        $this->postJson('/api/parties', [
            'party_type' => 'customer', 'code' => 'INACTIVE-SECRET',
            'name' => 'Secret Tenant Customer',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('inactive-customer-tenant-b')->assertOk();

        $this->getJson('/api/reports/accounts/inactive-customers?as_of=2026-11-08&min_days=1&max_days=30&include_never=1&q=Secret%20Tenant')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
