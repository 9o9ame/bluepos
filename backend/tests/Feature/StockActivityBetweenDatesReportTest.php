<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StockActivityBetweenDatesReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_activity_reconciles_signed_movements_and_warehouse_scope(): void
    {
        $owner = $this->signInOwner('stock-activity-a')->assertOk();
        $context = app(TenantContext::class);
        $product = $this->product('Activity Sample Product');
        $this->movement($product, $context->warehouseId(), '2026-09-30 12:00:00', '10.000000', StockMovementType::OpeningBalance);
        $this->movement($product, $context->warehouseId(), '2026-10-01 12:00:00', '5.000000', StockMovementType::Purchase);
        $this->movement($product, $context->warehouseId(), '2026-10-02 12:00:00', '-1.000000', StockMovementType::PurchaseReturn);
        $this->movement($product, $context->warehouseId(), '2026-10-03 12:00:00', '-4.000000', StockMovementType::Sale);
        $this->movement($product, $context->warehouseId(), '2026-10-04 12:00:00', '2.000000', StockMovementType::SaleReturn);
        $this->movement($product, $context->warehouseId(), '2026-10-05 12:00:00', '-3.000000', StockMovementType::AdjustmentOut);

        $other = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(), 'branch_id' => $context->branchId(),
            'code' => 'ACTIVITY-OTHER', 'name' => 'Other Activity Warehouse',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->movement($product, $other->id, '2026-10-06 12:00:00', '100.000000', StockMovementType::Purchase);

        $result = $this->getJson('/api/reports/products/stock-activity?from_date=2026-10-01&to_date=2026-10-31&q=Activity%20Sample')
            ->assertOk()->assertJsonPath('meta.warehouse.ulid', $owner->json('warehouse.ulid'))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.ulid', $product->ulid)
            ->assertJsonPath('data.0.opening', '10.000000')
            ->assertJsonPath('data.0.purchases', '5.000000')
            ->assertJsonPath('data.0.purchase_returns', '1.000000')
            ->assertJsonPath('data.0.sales', '4.000000')
            ->assertJsonPath('data.0.sale_returns', '2.000000')
            ->assertJsonPath('data.0.other_movements', '-3.000000')
            ->assertJsonPath('data.0.closing', '9.000000')
            ->assertJsonPath('data.0.purchase_cost', '5.0000')
            ->assertJsonPath('data.0.purchase_return_cost', '-1.0000')
            ->assertJsonPath('data.0.sale_cost', '-4.0000')
            ->assertJsonPath('data.0.sale_return_cost', '2.0000')
            ->assertJsonPath('data.0.closing_cost', '9.0000')
            ->assertJsonPath('meta.totals.closing', '9.000000')
            ->assertJsonPath('meta.category_totals.0.category', 'Unassigned')
            ->assertJsonPath('meta.category_totals.0.products', 1)
            ->assertJsonPath('meta.category_totals.0.opening', '10.000000')
            ->assertJsonPath('meta.category_totals.0.purchases', '5.000000')
            ->assertJsonPath('meta.category_totals.0.purchase_returns', '1.000000')
            ->assertJsonPath('meta.category_totals.0.sales', '4.000000')
            ->assertJsonPath('meta.category_totals.0.sale_returns', '2.000000')
            ->assertJsonPath('meta.category_totals.0.other_movements', '-3.000000')
            ->assertJsonPath('meta.category_totals.0.closing', '9.000000');
        $this->assertNoInternalIds($result->json());

        $this->getJson('/api/reports/products/stock-activity?from_date=2026-10-31&to_date=2026-10-01')
            ->assertUnprocessable();
    }

    public function test_category_summary_and_grand_totals_cover_all_pages_not_just_first_page(): void
    {
        $this->signInOwner('stock-activity-pages')->assertOk();
        $warehouseId = app(TenantContext::class)->warehouseId();
        $first = $this->product('Activity Page Alpha');
        $second = $this->product('Activity Page Beta');

        $this->movement($first, $warehouseId, '2026-09-30 12:00:00', '5.000000', StockMovementType::OpeningBalance);
        $this->movement($first, $warehouseId, '2026-10-01 12:00:00', '-2.250000', StockMovementType::Sale);
        $this->movement($second, $warehouseId, '2026-09-30 12:00:00', '1.000000', StockMovementType::OpeningBalance);
        $this->movement($second, $warehouseId, '2026-10-02 12:00:00', '3.500000', StockMovementType::Purchase);
        $this->movement($second, $warehouseId, '2026-11-02 12:00:00', '100.000000', StockMovementType::Purchase);

        $base = '/api/reports/products/stock-activity?from_date=2026-10-01&to_date=2026-10-31&q=Activity%20Page&per_page=1';
        foreach ([1, 2] as $number) {
            $response = $this->getJson($base.'&page='.$number)->assertOk()
                ->assertJsonPath('meta.total', 2)
                ->assertJsonPath('meta.last_page', 2)
                ->assertJsonPath('meta.totals.opening', '6.000000')
                ->assertJsonPath('meta.totals.purchases', '3.500000')
                ->assertJsonPath('meta.totals.sales', '2.250000')
                ->assertJsonPath('meta.totals.closing', '7.250000')
                ->assertJsonPath('meta.category_totals.0.products', 2)
                ->assertJsonPath('meta.category_totals.0.closing', '7.250000');

            $this->assertCount(1, $response->json('data'));
            $this->assertNoInternalIds($response->json());
        }
    }

    public function test_document_view_returns_real_immutable_movements_not_aggregated_document_columns(): void
    {
        $owner = $this->signInOwner('stock-activity-documents')->assertOk();
        $context = app(TenantContext::class);
        $product = $this->product('Document Activity Product');
        $this->movement($product, $context->warehouseId(),
            '2026-10-01 09:00:00', '2.500000', StockMovementType::Purchase);
        $this->movement($product, $context->warehouseId(),
            '2026-10-02 09:00:00', '-1.250000', StockMovementType::Sale);
        $this->movement($product, $context->warehouseId(),
            '2026-11-02 09:00:00', '100.000000', StockMovementType::Purchase);

        $base = '/api/reports/products/stock-activity?view=documents&from_date=2026-10-01&to_date=2026-10-31&q=Document%20Activity&per_page=1';
        $first = $this->getJson($base.'&page=1')->assertOk()
            ->assertJsonPath('meta.view', 'documents')
            ->assertJsonPath('meta.warehouse.ulid', $owner->json('warehouse.ulid'))
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.product.ulid', $product->ulid)
            ->assertJsonPath('data.0.movement_type', 'purchase')
            ->assertJsonPath('data.0.reference_type', 'test')
            ->assertJsonPath('data.0.quantity', '2.500000')
            ->assertJsonPath('data.0.movement_cost', '2.5000');
        $second = $this->getJson($base.'&page=2')->assertOk()
            ->assertJsonPath('data.0.movement_type', 'sale')
            ->assertJsonPath('data.0.quantity', '-1.250000')
            ->assertJsonPath('data.0.movement_cost', '-1.2500');
        $this->assertNotSame($first->json('data.0.movement_ulid'), $second->json('data.0.movement_ulid'));
        $this->assertNoInternalIds($first->json());
        $this->assertNoInternalIds($second->json());

        $this->getJson($base.'&view=invalid')->assertUnprocessable();
    }

    public function test_document_stock_cost_is_hidden_without_inventory_view_permission(): void
    {
        $this->signInOwner('stock-document-cost-guard')->assertOk();
        $product = $this->product('Cost Guard Document Item');
        $this->movement($product, app(TenantContext::class)->warehouseId(),
            '2026-10-07 12:00:00', '-1.250000', StockMovementType::Sale);

        $this->restrictCurrentMembershipWithoutPermission('inventory.view');

        $this->getJson('/api/reports/products/stock-activity?view=documents&from_date=2026-10-01&to_date=2026-10-31&q=Cost%20Guard')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.ulid', $product->ulid)
            ->assertJsonPath('data.0.quantity', '-1.250000')
            ->assertJsonPath('data.0.movement_cost', null);

        $this->restrictCurrentMembershipWithoutPermission('reports.inventory');
        $this->getJson('/api/reports/products/stock-activity?view=documents&from_date=2026-10-01&to_date=2026-10-31')
            ->assertForbidden();
    }

    public function test_stock_activity_is_tenant_scoped(): void
    {
        $this->signInOwner('stock-activity-secret')->assertOk();
        $product = $this->product('Secret Activity Stock');
        $this->movement($product, app(TenantContext::class)->warehouseId(),
            '2026-10-03 12:00:00', '3.000000', StockMovementType::Purchase);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('stock-activity-foreign')->assertOk();
        $this->getJson('/api/reports/products/stock-activity?from_date=2026-10-01&to_date=2026-10-31&q=Secret%20Activity')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function product(string $name): Product
    {
        $ulid = $this->postJson('/api/products', [
            'name' => $name, 'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');
        return Product::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function movement(Product $product, int $warehouseId, string $date, string $qty, StockMovementType $type): void
    {
        $context = app(TenantContext::class);
        StockMovement::query()->create([
            'tenant_id' => $context->tenantId(), 'branch_id' => $context->branchId(),
            'warehouse_id' => $warehouseId, 'product_id' => $product->id,
            'movement_type' => $type, 'quantity' => $qty,
            'unit_cost' => '1.0000', 'total_cost' => bcmul($qty, '1.0000', 4),
            'reference_type' => 'test', 'reference_ulid' => (string) Str::ulid(),
            'occurred_at' => $date, 'posted_by' => $context->userId(),
        ]);
    }

    private function unitUlid(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) return $unit['ulid'];
        }
        $this->fail('Missing unit '.$code);
    }
}
