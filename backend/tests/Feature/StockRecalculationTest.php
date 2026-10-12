<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Enums\StockMovementType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockRecalculationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_recalculation_repairs_cached_quantity_without_mutating_movements(): void
    {
        $this->signInOwner('stock-recalc-repair')->assertOk();

        $context = app(TenantContext::class);
        $product = $this->createProduct('Recalculation Product');

        $first = $this->movement($product, StockMovementType::OpeningBalance, '10.000000');
        $second = $this->movement($product, StockMovementType::Sale, '-2.500000');

        StockBalance::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
            'quantity' => '99.000000',
            'average_cost' => '4.0000',
            'stock_value' => '396.0000',
            'last_movement_id' => $first->id,
        ]);

        $response = $this->postJson('/api/inventory/stock/recalculate')
            ->assertOk()
            ->assertJsonPath('checked_balances', 1)
            ->assertJsonPath('corrected_balances', 1)
            ->assertJsonPath('corrected_products', 1)
            ->assertJsonPath('created_balances', 0)
            ->assertJsonPath('valuation_unavailable', 0);

        $balance = StockBalance::query()
            ->where('tenant_id', $context->tenantId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('7.500000', (string) $balance->quantity);
        $this->assertSame('4.0000', (string) $balance->average_cost);
        $this->assertSame('30.0000', (string) $balance->stock_value);
        $this->assertSame($second->id, (int) $balance->last_movement_id);

        $this->assertSame(2, StockMovement::query()->where('product_id', $product->id)->count());
        $this->assertSame('10.000000', (string) $first->fresh()->quantity);
        $this->assertSame('-2.500000', (string) $second->fresh()->quantity);
        $this->assertNoInternalIds($response->json());

        $this->postJson('/api/inventory/stock/recalculate')
            ->assertOk()
            ->assertJsonPath('checked_balances', 1)
            ->assertJsonPath('corrected_balances', 0)
            ->assertJsonPath('corrected_products', 0);
    }

    public function test_recalculation_creates_missing_balance_but_does_not_invent_average_cost(): void
    {
        $this->signInOwner('stock-recalc-missing')->assertOk();

        $context = app(TenantContext::class);
        $product = $this->createProduct('Missing Balance Product');
        $movement = $this->movement($product, StockMovementType::Purchase, '3.250000');

        $this->assertDatabaseMissing('stock_balances', [
            'tenant_id' => $context->tenantId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
        ]);

        $this->postJson('/api/inventory/stock/recalculate')
            ->assertOk()
            ->assertJsonPath('checked_balances', 1)
            ->assertJsonPath('corrected_balances', 1)
            ->assertJsonPath('corrected_products', 1)
            ->assertJsonPath('created_balances', 1)
            ->assertJsonPath('valuation_unavailable', 1);

        $balance = StockBalance::query()
            ->where('tenant_id', $context->tenantId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('3.250000', (string) $balance->quantity);
        $this->assertNull($balance->average_cost);
        $this->assertNull($balance->stock_value);
        $this->assertSame($movement->id, (int) $balance->last_movement_id);
    }

    public function test_recalculation_is_tenant_scoped(): void
    {
        $this->signInOwner('stock-recalc-tenant-a')->assertOk();

        $contextA = app(TenantContext::class);
        $tenantAId = $contextA->tenantId();
        $warehouseAId = $contextA->warehouseId();
        $branchAId = $contextA->branchId();
        $product = $this->createProduct('Tenant A Recalc Product');

        $this->movement($product, StockMovementType::OpeningBalance, '5.000000');

        StockBalance::query()->create([
            'tenant_id' => $tenantAId,
            'branch_id' => $branchAId,
            'warehouse_id' => $warehouseAId,
            'product_id' => $product->id,
            'quantity' => '88.000000',
            'average_cost' => '2.0000',
            'stock_value' => '176.0000',
            'last_movement_id' => null,
        ]);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('stock-recalc-tenant-b')->assertOk();

        $this->postJson('/api/inventory/stock/recalculate')
            ->assertOk()
            ->assertJsonPath('corrected_balances', 0);

        $foreign = StockBalance::query()
            ->where('tenant_id', $tenantAId)
            ->where('warehouse_id', $warehouseAId)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('88.000000', (string) $foreign->quantity);
        $this->assertSame('176.0000', (string) $foreign->stock_value);
    }

    public function test_recalculation_requires_explicit_permission(): void
    {
        $this->signInOwner('stock-recalc-permission')->assertOk();

        $context = app(TenantContext::class);
        $permission = Permission::query()->where('key', 'inventory.recalculate')->firstOrFail();

        foreach ($context->membership()->roles as $role) {
            $role->permissions()->detach($permission->id);
        }

        app(PermissionService::class)->clearCache();

        $this->postJson('/api/inventory/stock/recalculate')->assertForbidden();
    }

    private function createProduct(string $name): Product
    {
        $response = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated();

        return Product::query()->where('ulid', $response->json('ulid'))->firstOrFail();
    }

    private function movement(
        Product $product,
        StockMovementType $type,
        string $quantity,
    ): StockMovement {
        $context = app(TenantContext::class);

        return StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => null,
            'total_cost' => null,
            'reference_type' => 'stock_recalculation_test',
            'reference_ulid' => null,
            'reference_line_ulid' => null,
            'occurred_at' => now(),
            'posted_by' => $context->userId(),
            'idempotency_key' => null,
            'notes' => null,
        ]);
    }

    private function unitUlid(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
