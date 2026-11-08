<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class DeadStockReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dead_stock_uses_sales_inactivity_and_current_warehouse_only(): void
    {
        $this->signInOwner('dead-stock')->assertOk();
        $context = app(TenantContext::class);
        $stale = $this->product('Stale Product');
        $recent = $this->product('Recent Product');
        $empty = $this->product('No Balance Product');
        $this->balance($stale, $context->warehouseId(), '9.000000');
        $this->balance($recent, $context->warehouseId(), '4.000000');
        $this->balance($empty, $context->warehouseId(), '0.000000');

        $this->sale($stale, $context->warehouseId(), now()->subDays(180));
        $this->sale($recent, $context->warehouseId(), now()->subDays(3));

        $other = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'code' => 'DEAD-OTHER',
            'name' => 'Other Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);
        $this->sale($stale, $other->id, now()->subDays(2));

        $response = $this->getJson('/api/reports/products/dead-stock?days=90')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.ulid', $stale->ulid)
            ->assertJsonPath('data.0.quantity', '9.000000');
        $this->assertSame('90', (string) $response->json('meta.days'));
        $this->assertNoInternalIds($response->json());

        // A recent purchase breaks the reference "last stock activity" definition,
        // but does not break the optional "last outbound sale" definition.
        $this->sale($stale, $context->warehouseId(), now()->subDays(2), StockMovementType::Purchase);
        $this->getJson('/api/reports/products/dead-stock?days=90')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/reports/products/dead-stock?days=90&activity_mode=sale_only')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.ulid', $stale->ulid);

        $this->getJson('/api/reports/products/dead-stock?days=200')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/reports/products/dead-stock?days=0')->assertUnprocessable();
        $this->getJson('/api/reports/products/dead-stock?days=9999')->assertUnprocessable();
    }

    public function test_historical_as_of_uses_signed_movements_not_current_balance_or_future_activity(): void
    {
        $this->signInOwner('dead-stock-historical')->assertOk();
        $context = app(TenantContext::class);
        $product = $this->product('Historical Dead Stock Product');
        // Currently sold out, but at the historical cutoff the product
        // had five units on hand and had not moved for sixty days.
        $this->balance($product, $context->warehouseId(), '0.000000');
        $this->sale($product, $context->warehouseId(), '2026-09-01 12:00:00', StockMovementType::Purchase);
        $this->sale($product, $context->warehouseId(), '2026-11-02 12:00:00');

        $historical = $this->getJson('/api/reports/products/dead-stock?as_of=2026-10-31&days=30&q=Historical%20Dead')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.as_of', '2026-10-31')
            ->assertJsonPath('meta.valuation_basis', 'historical_stock_movements_quantity_only')
            ->assertJsonPath('data.0.product.ulid', $product->ulid)
            ->assertJsonPath('data.0.quantity', '1.000000')
            ->assertJsonPath('data.0.average_cost', null)
            ->assertJsonPath('data.0.stock_value', null)
            ->assertJsonPath('data.0.days_inactive', 60);
        $this->assertNoInternalIds($historical->json());

        // A sale after the selected date must never contaminate historical
        // quantities, but should affect a later as-of query.
        $this->getJson('/api/reports/products/dead-stock?as_of=2026-11-03&days=30&q=Historical%20Dead')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/reports/products/dead-stock?as_of=2027-01-01')
            ->assertUnprocessable();
    }

    public function test_dead_stock_restricts_cost_to_inventory_view_permission_and_is_tenant_scoped(): void
    {
        $owner = $this->signInOwner('dead-stock-secure')->assertOk();
        $context = app(TenantContext::class);
        $product = $this->product('Secret Dead Stock');
        $this->balance($product, $context->warehouseId(), '3.000000');

        $role = $this->postJson('/api/roles', [
            'name' => 'Dead Stock Reader',
            'code' => 'dead_stock_reader',
        ])->assertCreated();
        $this->putJson('/api/roles/'.$role->json('ulid').'/permissions', [
            'permissions' => ['reports.inventory'],
        ])->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Dead Stock Viewer',
            'username' => 'dead-stock-viewer',
            'recovery_email' => 'dead-stock-viewer@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$role->json('ulid')],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('dead-stock-secure', 'dead-stock-viewer')->assertOk();

        $this->getJson('/api/reports/products/dead-stock')->assertOk()
            ->assertJsonPath('data.0.product.ulid', $product->ulid)
            ->assertJsonPath('data.0.stock_value', null);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('dead-stock-other-tenant')->assertOk();
        $this->getJson('/api/reports/products/dead-stock?q=Secret%20Dead%20Stock')->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    private function product(string $name): Product
    {
        $ulid = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');
        return Product::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function balance(Product $product, int $warehouseId, string $quantity): void
    {
        $context = app(TenantContext::class);
        StockBalance::query()->updateOrCreate([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
        ], [
            'quantity' => $quantity,
            'average_cost' => '5.0000',
            'stock_value' => bcmul($quantity, '5.0000', 4),
        ]);
    }

    private function sale(Product $product, int $warehouseId, $occurredAt, StockMovementType $type = StockMovementType::Sale): void
    {
        $context = app(TenantContext::class);
        StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $type === StockMovementType::Purchase ? '1.000000' : '-1.000000',
            'unit_cost' => '5.0000',
            'total_cost' => $type === StockMovementType::Purchase ? '5.0000' : '-5.0000',
            'reference_type' => 'sale',
            'reference_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'occurred_at' => $occurredAt,
            'posted_by' => $context->userId(),
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
