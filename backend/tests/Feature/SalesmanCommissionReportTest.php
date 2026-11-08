<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SalesmanCommissionReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_salesman_register_returns_posted_sale_source_but_never_invents_commission(): void
    {
        $this->signInOwner('commission-source')->assertOk();
        $salesman = $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'name' => 'Verified Salesman Test',
        ])->assertCreated()->json('identity_ulid');

        $units = $this->getJson('/api/units')->assertOk()->json();
        $unit = collect($units)->firstWhere('code', 'PCS');
        $productUlid = $this->postJson('/api/products', [
            'name' => 'Commission Source Product',
            'base_unit_ulid' => $unit['ulid'],
        ])->assertCreated()->json('ulid');

        $this->putJson('/api/products/'.$productUlid.'/prices', [
            'prices' => [[
                'price_type' => 'retail', 'amount' => '100.0000', 'is_active' => true,
            ]],
        ])->assertOk();
        $context = app(TenantContext::class);
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        StockBalance::query()->updateOrCreate([
            'tenant_id' => $context->tenantId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
        ], [
            'branch_id' => $context->branchId(),
            'quantity' => '10.000000',
            'average_cost' => '10.0000',
            'stock_value' => '100.0000',
        ]);

        $this->postJson('/api/sales', [
            'sale_date' => '2026-11-04',
            'salesman_ulid' => $salesman,
            'items' => [['product_ulid' => $productUlid, 'quantity' => '2']],
        ], ['Idempotency-Key' => 'commission-source-test-sale'])->assertCreated();

        $result = $this->getJson('/api/reports/sales/salesman-commission?from_date=2026-11-04&to_date=2026-11-04')
            ->assertOk()->assertJsonPath('meta.commission_calculation_available', false)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.salesman.name', 'Verified Salesman Test')
            ->assertJsonPath('data.0.product.ulid', $productUlid)
            ->assertJsonPath('data.0.base_quantity', '2.000000')
            ->assertJsonPath('data.0.commission_percent', null)
            ->assertJsonPath('data.0.commission_per_base_unit', null)
            ->assertJsonPath('data.0.commission_amount', null);
        $this->assertNoInternalIds($result->json());

        $this->getJson('/api/reports/sales/salesman-commission?from_date=2026-10-01&to_date=2026-11-08')
            ->assertUnprocessable();
    }

    public function test_salesman_sales_do_not_leak_to_other_tenant(): void
    {
        $this->signInOwner('commission-tenant-a')->assertOk();
        $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'name' => 'Tenant A Source Salesman',
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('commission-tenant-b')->assertOk();
        $this->getJson('/api/reports/sales/salesman-commission?from_date=2026-11-04&to_date=2026-11-04')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
