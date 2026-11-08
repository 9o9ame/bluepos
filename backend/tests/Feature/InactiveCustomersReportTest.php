<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
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
        $this->getJson($base.'&include_never=1&having_balance_only=1')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $equity = $this->postJson('/api/parties', [
            'party_type' => 'account', 'code' => 'INACTIVE-EQ',
            'name' => 'Inactive Equity',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0050'),
        ])->assertCreated();
        BusinessSetting::query()->forTenant(app(TenantContext::class)->tenantId())
            ->update(['opening_balance_equity_account_id' => Account::query()
                ->where('ulid', $equity->json('ulid'))->value('id')]);
        $opening = $this->postJson('/api/parties/'.$customer->json('ulid').'/opening-balances?type=customer', [
            'opening_date' => '2026-10-01', 'debit' => '150.0000',
            'credit' => '0.0000', 'narration' => 'Inactive customer debt',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/parties/'.$customer->json('ulid').'/opening-balances/'.$opening.'/post?type=customer')
            ->assertOk();
        $this->getJson($base.'&include_never=1&having_balance_only=1')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertNoInternalIds($found->json());
        $this->getJson('/api/reports/accounts/inactive-customers?as_of=2026-11-08&min_days=31&max_days=1')
            ->assertUnprocessable();
    }

    public function test_posted_sales_date_boundaries_area_and_active_branch_are_respected(): void
    {
        $this->signInOwner('inactive-posted-date-boundaries')->assertOk();
        $context = app(TenantContext::class);
        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'INACTIVE-POSTED-1',
            'name' => 'Inactive Posted Boundary Customer',
            'area' => ' RYK ',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated();
        $customerId = \App\Models\Customer::query()
            ->where('ulid', $customer->json('ulid'))->value('id');

        $otherBranch = Branch::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'INACTIVE-OTHER',
            'name' => 'Other Inactive Branch',
            'status' => 'active',
            'is_default' => false,
        ]);
        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'code' => 'INACTIVE-OTHER-WH',
            'name' => 'Other Inactive Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        foreach ([
            ['2026-10-09', 'posted', $context->branchId(), $context->warehouseId(), 'POSTED-OLD'],
            ['2026-11-07', 'void', $context->branchId(), $context->warehouseId(), 'VOID-RECENT'],
            ['2026-11-07', 'posted', $otherBranch->id, $otherWarehouse->id, 'OTHER-BRANCH'],
            ['2026-11-09', 'posted', $context->branchId(), $context->warehouseId(), 'POSTED-FUTURE'],
        ] as [$date, $status, $branchId, $warehouseId, $documentNumber]) {
            Sale::query()->create([
                'tenant_id' => $context->tenantId(),
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'document_number' => $documentNumber,
                'sale_date' => $date,
                'status' => $status,
                'price_type' => 'retail',
                'subtotal' => '25.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'grand_total' => '25.0000',
                'idempotency_key' => 'inactive-'.strtolower($documentNumber),
                'created_by' => $context->userId(),
                'posted_at' => $status === 'posted' ? now() : null,
            ]);
        }

        $base = '/api/reports/accounts/inactive-customers?as_of=2026-11-08&min_days=30&max_days=30&q=Inactive%20Posted%20Boundary';
        $report = $this->getJson($base.'&area=ryk')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.area', 'ryk')
            ->assertJsonPath('data.0.ulid', $customer->json('ulid'))
            ->assertJsonPath('data.0.last_sale_date', '2026-10-09')
            ->assertJsonPath('data.0.days_inactive', 30);
        $this->assertNoInternalIds($report->json());

        $this->getJson($base.'&area=Lahore')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(str_replace('min_days=30&max_days=30', 'min_days=29&max_days=29', $base))
            ->assertOk()->assertJsonPath('meta.total', 0);
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
