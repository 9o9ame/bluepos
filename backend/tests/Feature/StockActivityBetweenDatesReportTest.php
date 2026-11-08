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
            ->assertJsonPath('meta.totals.closing', '9.000000');
        $this->assertNoInternalIds($result->json());

        $this->getJson('/api/reports/products/stock-activity?from_date=2026-10-31&to_date=2026-10-01')
            ->assertUnprocessable();
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
