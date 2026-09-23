<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryOpeningBalanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_opening_balance_draft_lifecycle_and_posting(): void
    {
        $this->signInOwner('ob-life')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Opening Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $document = $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseUlid,
            'notes' => 'Initial stock',
        ])->assertCreated();
        $documentUlid = $document->json('ulid');
        $document->assertJsonPath('status', 'draft');
        $this->assertNoInternalIds($document->json());
        $this->assertTrue(AuditLog::query()->where('event', 'OPENING_BALANCE_CREATED')->where('resource_ulid', $documentUlid)->exists());

        $this->patchJson('/api/inventory/opening-balances/'.$documentUlid, [
            'notes' => 'Updated notes',
        ])->assertOk()->assertJsonPath('notes', 'Updated notes');

        $line = $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/lines', [
            'product_ulid' => $productUlid,
            'quantity' => '10.000000',
            'unit_cost' => '12.5000',
        ])->assertCreated();
        $lineUlid = $line->json('ulid');
        $line->assertJsonPath('total_cost', '125.0000');
        $this->assertNoInternalIds($line->json());

        $this->patchJson('/api/inventory/opening-balances/'.$documentUlid.'/lines/'.$lineUlid, [
            'quantity' => '8.000000',
            'unit_cost' => '10.0000',
        ])->assertOk()
            ->assertJsonPath('quantity', '8.000000')
            ->assertJsonPath('total_cost', '80.0000');

        $posted = $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/post')->assertOk();
        $posted->assertJsonPath('status', 'posted');
        $this->assertTrue(AuditLog::query()->where('event', 'OPENING_BALANCE_POSTED')->where('resource_ulid', $documentUlid)->exists());

        $this->assertSame(1, StockMovement::query()->count());
        $movement = StockMovement::query()->firstOrFail();
        $this->assertSame('opening_balance', $movement->movement_type->value);
        $this->assertSame('8.000000', (string) $movement->quantity);
        $this->assertSame('10.0000', (string) $movement->unit_cost);
        $this->assertSame('80.0000', (string) $movement->total_cost);

        $balance = StockBalance::query()->firstOrFail();
        $this->assertSame('8.000000', (string) $balance->quantity);
        $this->assertSame('10.0000', (string) $balance->average_cost);
        $this->assertSame('80.0000', (string) $balance->stock_value);

        $sum = (string) StockMovement::query()->sum('quantity');
        $this->assertSame('8.000000', bcadd($sum, '0', 6));

        $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/post')->assertOk();
        $this->assertSame(1, StockMovement::query()->count());

        $this->patchJson('/api/inventory/opening-balances/'.$documentUlid, [
            'notes' => 'Nope',
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/lines', [
            'product_ulid' => $productUlid,
            'quantity' => '1.000000',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);

        $this->deleteJson('/api/inventory/opening-balances/'.$documentUlid.'/lines/'.$lineUlid)
            ->assertStatus(422);

        $stock = $this->getJson('/api/products/'.$productUlid.'/stock')->assertOk();
        $stock->assertJsonPath('active_warehouse.quantity', '8.000000');
        $stock->assertJsonPath('total_quantity', '8.000000');
        $this->assertNoInternalIds($stock->json());

        $list = $this->getJson('/api/inventory/stock?product_ulid='.$productUlid)->assertOk();
        $this->assertSame('8.000000', $list->json('data.0.quantity'));
        $this->assertNoInternalIds($list->json());
    }

    public function test_opening_balance_validations_and_isolation(): void
    {
        $this->signInOwner('ob-iso-a')->assertOk();
        $warehouseA = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $productA = $this->postJson('/api/products', [
            'name' => 'Private Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $documentA = $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseA,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/opening-balances/'.$documentA.'/lines', [
            'product_ulid' => $productA,
            'quantity' => '0',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);

        $this->postJson('/api/inventory/opening-balances/'.$documentA.'/lines', [
            'product_ulid' => $productA,
            'quantity' => '-1.000000',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);

        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'inactive']);
        $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseA,
        ])->assertStatus(422);
        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'active']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('ob-iso-b')->assertOk();
        $warehouseB = $this->sessionWarehouseUlid();

        $this->getJson('/api/inventory/opening-balances/'.$documentA)->assertNotFound();
        $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseA,
        ])->assertNotFound();

        $documentB = $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseB,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/opening-balances/'.$documentB.'/lines', [
            'product_ulid' => $productA,
            'quantity' => '2.000000',
            'unit_cost' => '3.0000',
        ])->assertNotFound();
    }

    public function test_line_delete_and_failed_validation_leaves_no_movements(): void
    {
        $this->signInOwner('ob-roll')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $productUlid = $this->postJson('/api/products', [
            'name' => 'Draft Only',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $documentUlid = $this->postJson('/api/inventory/opening-balances', [
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $lineUlid = $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/lines', [
            'product_ulid' => $productUlid,
            'quantity' => '5.000000',
            'unit_cost' => '2.0000',
        ])->assertCreated()->json('ulid');

        $this->deleteJson('/api/inventory/opening-balances/'.$documentUlid.'/lines/'.$lineUlid)
            ->assertOk();

        $this->postJson('/api/inventory/opening-balances/'.$documentUlid.'/post')
            ->assertStatus(422);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
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
