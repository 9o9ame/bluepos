<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryStockTakeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_take_recomputes_authoritative_variance_and_posts_adjustment_out(): void
    {
        $this->signInOwner('st-life')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $productUlid = $this->createProduct('Counted Product');

        $this->postOpeningStock($warehouseUlid, $productUlid, '10.000000', '5.0000');

        $document = $this->postJson('/api/inventory/stock-takes', [
            'warehouse_ulid' => $warehouseUlid,
            'notes' => 'Cycle count',
        ])->assertCreated();
        $documentUlid = $document->json('ulid');
        $document->assertJsonPath('status', 'draft');
        $this->assertNoInternalIds($document->json());
        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'STOCK_TAKE_CREATED')
                ->where('resource_ulid', $documentUlid)
                ->exists()
        );

        $line = $this->postJson('/api/inventory/stock-takes/'.$documentUlid.'/lines', [
            'product_ulid' => $productUlid,
            'counted_quantity' => '7.000000',
        ])->assertCreated();
        $lineUlid = $line->json('ulid');
        $line->assertJsonPath('system_quantity', '10.000000');
        $line->assertJsonPath('counted_quantity', '7.000000');
        $line->assertJsonPath('variance_quantity', '-3.000000');
        $this->assertNoInternalIds($line->json());

        // Stock changes after the draft count line was captured. Posting must not trust
        // the stale draft system quantity; it must lock/re-read current stock.
        $this->postOpeningStock($warehouseUlid, $productUlid, '2.000000', '5.0000');

        $posted = $this->postJson('/api/inventory/stock-takes/'.$documentUlid.'/post')->assertOk();
        $posted->assertJsonPath('status', 'posted');
        $posted->assertJsonPath('lines.0.system_quantity', '12.000000');
        $posted->assertJsonPath('lines.0.counted_quantity', '7.000000');
        $posted->assertJsonPath('lines.0.variance_quantity', '-5.000000');
        $this->assertNoInternalIds($posted->json());

        $movement = StockMovement::query()
            ->where('reference_type', 'inventory_stock_take')
            ->where('reference_ulid', $documentUlid)
            ->firstOrFail();
        $this->assertSame('adjustment_out', $movement->movement_type->value);
        $this->assertSame('-5.000000', (string) $movement->quantity);
        $this->assertSame('5.0000', (string) $movement->unit_cost);

        $balance = StockBalance::query()->whereHas('product', fn ($q) => $q->where('ulid', $productUlid))->firstOrFail();
        $this->assertSame('7.000000', (string) $balance->quantity);
        $this->assertSame('5.0000', (string) $balance->average_cost);
        $this->assertSame('35.0000', (string) $balance->stock_value);

        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'STOCK_TAKE_POSTED')
                ->where('resource_ulid', $documentUlid)
                ->exists()
        );

        $this->postJson('/api/inventory/stock-takes/'.$documentUlid.'/post')->assertOk();
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', 'inventory_stock_take')
                ->where('reference_ulid', $documentUlid)
                ->count()
        );

        $this->patchJson('/api/inventory/stock-takes/'.$documentUlid, [
            'notes' => 'Nope',
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->patchJson('/api/inventory/stock-takes/'.$documentUlid.'/lines/'.$lineUlid, [
            'counted_quantity' => '8.000000',
        ])->assertStatus(422);

        $this->deleteJson('/api/inventory/stock-takes/'.$documentUlid.'/lines/'.$lineUlid)
            ->assertStatus(422);
        $this->deleteJson('/api/inventory/stock-takes/'.$documentUlid)
            ->assertStatus(422);
    }

    public function test_stock_take_posts_adjustment_in_and_zero_variance_is_noop(): void
    {
        $this->signInOwner('st-in')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $productA = $this->createProduct('Short System Stock');
        $productB = $this->createProduct('Zero Variance Stock');

        $this->postOpeningStock($warehouseUlid, $productA, '4.000000', '3.0000');

        $documentUlid = $this->postJson('/api/inventory/stock-takes', [
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/stock-takes/'.$documentUlid.'/lines', [
            'product_ulid' => $productA,
            'counted_quantity' => '6.000000',
        ])->assertCreated()->assertJsonPath('variance_quantity', '2.000000');

        $this->postJson('/api/inventory/stock-takes/'.$documentUlid.'/lines', [
            'product_ulid' => $productB,
            'counted_quantity' => '0',
        ])->assertCreated()->assertJsonPath('variance_quantity', '0.000000');

        $this->postJson('/api/inventory/stock-takes/'.$documentUlid.'/post')->assertOk();

        $movements = StockMovement::query()
            ->where('reference_type', 'inventory_stock_take')
            ->where('reference_ulid', $documentUlid)
            ->get();

        $this->assertCount(1, $movements);
        $this->assertSame('adjustment_in', $movements->first()->movement_type->value);
        $this->assertSame('2.000000', (string) $movements->first()->quantity);

        $balanceA = StockBalance::query()->whereHas('product', fn ($q) => $q->where('ulid', $productA))->firstOrFail();
        $this->assertSame('6.000000', (string) $balanceA->quantity);
        $this->assertSame('3.0000', (string) $balanceA->average_cost);
        $this->assertSame('18.0000', (string) $balanceA->stock_value);

        $balanceB = StockBalance::query()->whereHas('product', fn ($q) => $q->where('ulid', $productB))->firstOrFail();
        $this->assertSame('0.000000', (string) $balanceB->quantity);
    }

    public function test_stock_take_validation_and_tenant_isolation(): void
    {
        $this->signInOwner('st-iso-a')->assertOk();
        $warehouseA = $this->sessionWarehouseUlid();
        $productA = $this->createProduct('Private Count Product');

        $documentA = $this->postJson('/api/inventory/stock-takes', [
            'warehouse_ulid' => $warehouseA,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/stock-takes/'.$documentA.'/lines', [
            'product_ulid' => $productA,
            'counted_quantity' => '-1.000000',
        ])->assertStatus(422);

        $this->postJson('/api/inventory/stock-takes/'.$documentA.'/post')
            ->assertStatus(422);
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('reference_type', 'inventory_stock_take')
                ->count()
        );

        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'inactive']);
        $this->postJson('/api/inventory/stock-takes', [
            'warehouse_ulid' => $warehouseA,
        ])->assertStatus(422);
        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'active']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('st-iso-b')->assertOk();
        $warehouseB = $this->sessionWarehouseUlid();

        $this->getJson('/api/inventory/stock-takes/'.$documentA)->assertNotFound();
        $this->patchJson('/api/inventory/stock-takes/'.$documentA, ['notes' => 'Leak'])
            ->assertNotFound();
        $this->postJson('/api/inventory/stock-takes', [
            'warehouse_ulid' => $warehouseA,
        ])->assertNotFound();

        $documentB = $this->postJson('/api/inventory/stock-takes', [
            'warehouse_ulid' => $warehouseB,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/stock-takes/'.$documentB.'/lines', [
            'product_ulid' => $productA,
            'counted_quantity' => '1.000000',
        ])->assertNotFound();
    }

    private function createProduct(string $name): string
    {
        return (string) $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');
    }

    private function postOpeningStock(
        string $warehouseUlid,
        string $productUlid,
        string $quantity,
        string $unitCost,
    ): void {
        $documentUlid = (string) $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/lines', [
            'product_ulid' => $productUlid,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ])->assertCreated();

        $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/post')
            ->assertOk();
    }

    private function sessionWarehouseUlid(): string
    {
        return (string) $this->getJson('/api/auth/me')->assertOk()->json('warehouse.ulid');
    }

    private function unitUlid(string $code): string
    {
        $units = $this->getJson('/api/units')->assertOk()->json();
        foreach ($units as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
