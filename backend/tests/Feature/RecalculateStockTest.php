<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecalculateStockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_recalculate_repairs_active_warehouse_balance_from_immutable_movements_in_posting_order(): void
    {
        $session = $this->signInOwner('recalc-stock')->assertOk();
        $context = app(TenantContext::class);

        $productUlid = (string) $this->postJson('/api/products', [
            'name' => 'Recalc Product',
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');

        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        $opening = $this->movement(
            $product,
            StockMovementType::OpeningBalance,
            '10.000000',
            '10.0000',
            '2026-10-10 09:00:00',
        );
        $this->movement(
            $product,
            StockMovementType::Sale,
            '-8.000000',
            '10.0000',
            '2026-10-20 09:00:00',
        );
        $purchase = $this->movement(
            $product,
            StockMovementType::Purchase,
            '8.000000',
            '20.0000',
            '2026-09-01 09:00:00',
        );

        StockBalance::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
            'quantity' => '999.000000',
            'average_cost' => '99.0000',
            'stock_value' => '98901.0000',
            'last_movement_id' => $opening->id,
        ]);

        $before = StockMovement::query()
            ->forTenant($context->tenantId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get(['ulid', 'movement_type', 'quantity', 'unit_cost', 'total_cost', 'occurred_at'])
            ->map(fn (StockMovement $row) => [
                'ulid' => $row->ulid,
                'movement_type' => $row->movement_type->value,
                'quantity' => (string) $row->quantity,
                'unit_cost' => $row->unit_cost === null ? null : (string) $row->unit_cost,
                'total_cost' => $row->total_cost === null ? null : (string) $row->total_cost,
                'occurred_at' => $row->occurred_at?->toISOString(),
            ])
            ->all();

        $response = $this->postJson('/api/inventory/recalculate-stock')
            ->assertOk()
            ->assertJsonPath('warehouse.ulid', $session->json('warehouse.ulid'))
            ->assertJsonPath('processed_products', 1)
            ->assertJsonPath('changed_balances', 1)
            ->assertJsonPath('movement_count', 3);

        $this->assertNoInternalIds($response->json());

        $balance = StockBalance::query()
            ->forTenant($context->tenantId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('10.000000', (string) $balance->quantity);
        $this->assertSame('18.0000', (string) $balance->average_cost);
        $this->assertSame('180.0000', (string) $balance->stock_value);
        $this->assertSame($purchase->id, $balance->last_movement_id);

        $after = StockMovement::query()
            ->forTenant($context->tenantId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get(['ulid', 'movement_type', 'quantity', 'unit_cost', 'total_cost', 'occurred_at'])
            ->map(fn (StockMovement $row) => [
                'ulid' => $row->ulid,
                'movement_type' => $row->movement_type->value,
                'quantity' => (string) $row->quantity,
                'unit_cost' => $row->unit_cost === null ? null : (string) $row->unit_cost,
                'total_cost' => $row->total_cost === null ? null : (string) $row->total_cost,
                'occurred_at' => $row->occurred_at?->toISOString(),
            ])
            ->all();

        $this->assertSame($before, $after);

        $this->postJson('/api/inventory/recalculate-stock')
            ->assertOk()
            ->assertJsonPath('changed_balances', 0)
            ->assertJsonPath('movement_count', 3);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'STOCK_BALANCES_RECALCULATED',
            'resource_ulid' => $session->json('warehouse.ulid'),
        ]);
    }

    public function test_recalculate_is_active_context_scoped_and_requires_inventory_adjust_permission(): void
    {
        $owner = $this->signInOwner('recalc-perm')->assertOk();
        $context = app(TenantContext::class);

        $productUlid = (string) $this->postJson('/api/products', [
            'name' => 'Scoped Recalc Product',
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'code' => 'OTHER-RECALC',
            'name' => 'Other Recalc Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        StockBalance::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $product->id,
            'quantity' => '77.000000',
            'average_cost' => '7.0000',
            'stock_value' => '539.0000',
            'last_movement_id' => null,
        ]);

        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $cashier = collect($roles)->firstWhere('code', 'cashier');

        $this->postJson('/api/memberships', [
            'name' => 'Recalc Cashier',
            'username' => 'recalc-cashier',
            'recovery_email' => 'recalc-cashier@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashier['ulid']],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('recalc-perm', 'recalc-cashier')->assertOk();

        $this->postJson('/api/inventory/recalculate-stock')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FORBIDDEN');

        $other = StockBalance::query()
            ->where('warehouse_id', $otherWarehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('77.000000', (string) $other->quantity);
        $this->assertSame('7.0000', (string) $other->average_cost);
        $this->assertSame('539.0000', (string) $other->stock_value);
    }

    private function movement(
        Product $product,
        StockMovementType $type,
        string $quantity,
        string $unitCost,
        string $occurredAt,
    ): StockMovement {
        $context = app(TenantContext::class);

        return StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => bcmul($quantity, $unitCost, 4),
            'reference_type' => 'recalc_test',
            'reference_ulid' => (string) Str::ulid(),
            'occurred_at' => $occurredAt,
            'posted_by' => $context->userId(),
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
