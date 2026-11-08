<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductDailyReportsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_movements_reconcile_opening_period_closing_and_isolate_warehouse(): void
    {
        $this->signInOwner('daily-stock-facts')->assertOk();
        $product = $this->product('Daily Stock Reconciliation', '80.0000');
        $this->movement($product, '2026-10-31', '5.000000', '10.0000', StockMovementType::OpeningBalance);
        $this->movement($product, '2026-11-04', '2.000000', '11.0000', StockMovementType::Purchase);
        $this->movement($product, '2026-11-04', '-1.000000', '10.0000', StockMovementType::Sale);
        $context = app(TenantContext::class);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(), 'branch_id' => $context->branchId(),
            'code' => 'DAILY-OTHER', 'name' => 'Other warehouse',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->movement($product, '2026-11-04', '100.000000', '20.0000',
            StockMovementType::Purchase, (int) $warehouse->id);
        $otherBranch = Branch::factory()->create(['tenant_id' => $context->tenantId()]);
        $otherBranchWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(), 'branch_id' => $otherBranch->id,
            'code' => 'DAILY-BRANCH', 'name' => 'Other branch warehouse',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->movement($product, '2026-11-04', '300.000000', '20.0000',
            StockMovementType::Purchase, (int) $otherBranchWarehouse->id, (int) $otherBranch->id);

        $response = $this->getJson('/api/reports/daily/detail-sale-stock-profit?from=2026-11-01&to=2026-11-05')
            ->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.product.ulid', $product->ulid)
            ->assertJsonPath('rows.0.opening_quantity', '5.000000')
            ->assertJsonPath('rows.0.movements.purchase', '2.000000')
            ->assertJsonPath('rows.0.movements.sale', '-1.000000')
            ->assertJsonPath('rows.0.period_change', '1.000000')
            ->assertJsonPath('rows.0.closing_quantity', '6.000000')
            ->assertJsonPath('rows.0.cost_of_sales', '10.0000');
        $this->assertNoInternalIds($response->json());
    }

    public function test_grouped_stock_change_matches_ungrouped_and_profit_is_not_invented(): void
    {
        $this->signInOwner('daily-group-facts')->assertOk();
        $product = $this->product('Daily Group Product', '100.0000');
        $this->movement($product, '2026-11-02', '3.000000', '10.0000', StockMovementType::Purchase);
        $this->movement($product, '2026-11-03', '-1.000000', '10.0000', StockMovementType::Sale);

        $grouped = $this->getJson(
            '/api/reports/daily/business-by-groups?from=2026-11-01&to=2026-11-05&group_by=category'
        )->assertOk()->assertJsonPath('totals.stock_change', '2.000000')
            ->assertJsonPath('totals.sale_amount', '0.0000');
        $this->assertNoInternalIds($grouped->json());

        $this->getJson('/api/reports/daily/profit-loss?from=2026-11-01&to=2026-11-05')
            ->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_posted_sale_uses_actual_movement_cost_for_product_gross_profit(): void
    {
        $this->signInOwner('daily-profit-facts')->assertOk();
        $product = $this->product('Daily Profit Product', '100.0000');
        $context = app(TenantContext::class);
        StockBalance::query()->updateOrCreate([
            'tenant_id' => $context->tenantId(), 'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
        ], [
            'branch_id' => $context->branchId(),
            'quantity' => '10.000000',
            'average_cost' => '10.0000',
            'stock_value' => '100.0000',
        ]);

        $this->postJson('/api/sales', [
            'sale_date' => '2026-11-04',
            'items' => [['product_ulid' => $product->ulid, 'quantity' => '2']],
        ], ['Idempotency-Key' => 'daily-profit-facts-sale'])->assertCreated();

        $response = $this->getJson('/api/reports/daily/profit-loss?from=2026-11-04&to=2026-11-04')
            ->assertOk()->assertJsonPath('rows.0.product.ulid', $product->ulid)
            ->assertJsonPath('rows.0.sale_quantity', '2.000000')
            ->assertJsonPath('rows.0.sale_amount', '200.0000')
            ->assertJsonPath('rows.0.cost_amount', '20.0000')
            ->assertJsonPath('rows.0.sale_rate', '100.0000')
            ->assertJsonPath('rows.0.cost_rate', '10.0000')
            ->assertJsonPath('rows.0.gross_profit', '180.0000')
            ->assertJsonPath('totals.gross_profit', '180.0000');
        $this->assertNoInternalIds($response->json());

        $grouped = $this->getJson(
            '/api/reports/daily/business-by-groups?from=2026-11-04&to=2026-11-04&group_by=category'
        )->assertOk()->assertJsonPath('totals.sale_amount', '200.0000')
            ->assertJsonPath('totals.sale_quantity', '2.000000');
        $this->assertNoInternalIds($grouped->json());
    }

    public function test_other_tenant_cannot_read_first_tenant_stock_or_profit(): void
    {
        $this->signInOwner('daily-tenant-a')->assertOk();
        $product = $this->product('Private First Tenant Stock', '100.0000');
        $this->movement($product, '2026-11-04', '4.000000', '20.0000', StockMovementType::Purchase);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('daily-tenant-b')->assertOk();
        $this->getJson('/api/reports/daily/detail-sale-stock-profit?from=2026-11-01&to=2026-11-05')
            ->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/reports/daily/business-by-groups?from=2026-11-01&to=2026-11-05&group_by=brand')
            ->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/reports/daily/profit-loss?from=2026-11-01&to=2026-11-05')
            ->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_dates_filters_and_permission_are_enforced(): void
    {
        $this->signInOwner('daily-product-guards')->assertOk();
        $url = '/api/reports/daily/detail-sale-stock-profit';
        $this->getJson($url.'?from=2026-12-05&to=2026-12-01')->assertUnprocessable();
        $this->getJson($url.'?from=2026-01-01&to=2026-12-01')->assertUnprocessable();
        $this->getJson($url.'?from=2026-11-01&to=2026-11-05&category_ulid=01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertUnprocessable();
        $this->getJson('/api/reports/daily/business-by-groups?from=2026-11-01&to=2026-11-05&group_by=invalid')
            ->assertUnprocessable();

        $this->restrictCurrentMembershipWithoutPermission('reports.inventory');
        $this->getJson($url.'?from=2026-11-01&to=2026-11-05')->assertForbidden();
        $this->getJson('/api/reports/daily/profit-loss?from=2026-11-01&to=2026-11-05')
            ->assertForbidden();
    }

    public function test_missing_cost_snapshot_does_not_create_fake_profit(): void
    {
        $this->signInOwner('daily-profit-no-cost')->assertOk();
        $product = $this->product('Daily Cost Unavailable', '90.0000');
        $context = app(TenantContext::class);
        StockBalance::query()->updateOrCreate([
            'tenant_id' => $context->tenantId(), 'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
        ], [
            'branch_id' => $context->branchId(),
            'quantity' => '10.000000',
            'average_cost' => null,
            'stock_value' => null,
        ]);
        $this->postJson('/api/sales', [
            'sale_date' => '2026-11-04',
            'items' => [['product_ulid' => $product->ulid, 'quantity' => '2']],
        ], ['Idempotency-Key' => 'daily-profit-no-cost-sale'])->assertCreated();
        $response = $this->getJson('/api/reports/daily/profit-loss?from=2026-11-04&to=2026-11-04')
            ->assertOk()
            ->assertJsonPath('rows.0.sale_amount', '180.0000')
            ->assertJsonPath('rows.0.cost_verified', false)
            ->assertJsonPath('rows.0.cost_amount', null)
            ->assertJsonPath('rows.0.gross_profit', null)
            ->assertJsonPath('totals.gross_profit', null);
        $this->assertNoInternalIds($response->json());
    }

    public function test_inventory_reporter_cannot_read_stock_cost_without_inventory_view(): void
    {
        $this->signInOwner('daily-cost-authz')->assertOk();
        $product = $this->product('Daily Restricted Cost', '100.0000');
        $this->movement($product, '2026-11-04', '1.000000', '25.0000', StockMovementType::Purchase);
        $this->restrictCurrentMembershipWithoutPermission('inventory.view');

        $this->getJson('/api/reports/daily/detail-sale-stock-profit?from=2026-11-01&to=2026-11-05')
            ->assertOk()
            ->assertJsonPath('rows.0.cost_of_sales', null);
        $this->getJson('/api/reports/daily/profit-loss?from=2026-11-01&to=2026-11-05')
            ->assertForbidden();
    }

    private function product(string $name, string $price): Product
    {
        $units = $this->getJson('/api/units')->assertOk()->json();
        $unit = collect($units)->firstWhere('code', 'PCS');
        $this->assertNotNull($unit);
        $result = $this->postJson('/api/products', [
            'name' => $name, 'base_unit_ulid' => $unit['ulid'],
            'prices' => [['price_type' => 'retail', 'amount' => $price]],
        ])->assertCreated();

        return Product::query()->where('ulid', $result->json('ulid'))->firstOrFail();
    }

    private function movement(
        Product $product, string $date, string $qty, string $cost,
        StockMovementType $type, ?int $warehouse = null, ?int $branch = null,
    ): void {
        $context = app(TenantContext::class);
        StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $branch ?? $context->branchId(),
            'warehouse_id' => $warehouse ?? $context->warehouseId(),
            'product_id' => $product->id, 'movement_type' => $type,
            'quantity' => $qty, 'unit_cost' => $cost,
            'total_cost' => bcmul($qty, $cost, 4),
            'reference_type' => 'report_test',
            'reference_ulid' => '01'.str_pad((string) (StockMovement::query()->count() + 1), 24, '0', STR_PAD_LEFT),
            'occurred_at' => $date.' 12:00:00', 'posted_by' => $context->userId(),
        ]);
    }
}
