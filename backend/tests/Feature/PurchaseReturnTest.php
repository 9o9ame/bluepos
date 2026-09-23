<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_return_lifecycle_stock_and_costing(): void
    {
        $this->signInOwner('pr-life')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $ctn = $this->unitUlid('CTN');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PR-1',
            'name' => 'Return Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Return Carton Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $purchaseUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
        ])->assertStatus(422);

        $purchaseLineUlid = $this->postJson('/api/purchases/'.$purchaseUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $ctn,
            'quantity' => '2.000000',
            'conversion_factor' => '24.00000000',
            'unit_cost' => '2400.0000',
            'discount_amount' => '100.0000',
            'tax_amount' => '50.0000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$purchaseUlid.'/post')->assertOk();

        $returnable = $this->getJson('/api/purchases/'.$purchaseUlid.'/returnable-lines')->assertOk();
        $returnable->assertJsonPath('data.0.remaining_returnable_base_quantity', '48.000000');
        $this->assertNoInternalIds($returnable->json());

        $document = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
            'warehouse_ulid' => $warehouseUlid,
            'reason' => 'Damaged',
        ])->assertCreated();

        $returnUlid = $document->json('ulid');
        $document->assertJsonPath('status', 'draft');
        $document->assertJsonPath('document_number', 'PR-000001');
        $document->assertJsonPath('supplier.ulid', $supplierUlid);
        $document->assertJsonPath('original_purchase.ulid', $purchaseUlid);
        $this->assertNoInternalIds($document->json());
        $this->assertTrue(AuditLog::query()->where('event', 'PURCHASE_RETURN_CREATED')->where('resource_ulid', $returnUlid)->exists());

        $this->patchJson('/api/purchase-returns/'.$returnUlid, [
            'notes' => 'Partial carton return',
        ])->assertOk()->assertJsonPath('notes', 'Partial carton return');

        $line = $this->postJson('/api/purchase-returns/'.$returnUlid.'/lines', [
            'purchase_line_ulid' => $purchaseLineUlid,
            'quantity' => '1.000000',
        ])->assertCreated();

        $lineUlid = $line->json('ulid');
        $line->assertJsonPath('base_quantity', '24.000000');
        $line->assertJsonPath('unit_cost', '2400.0000');
        $line->assertJsonPath('discount_amount', '50.0000');
        $line->assertJsonPath('tax_amount', '25.0000');
        $line->assertJsonPath('line_total', '2375.0000');
        $this->assertNoInternalIds($line->json());

        $shown = $this->getJson('/api/purchase-returns/'.$returnUlid)->assertOk();
        $shown->assertJsonPath('subtotal', '2400.0000');
        $shown->assertJsonPath('grand_total', '2375.0000');

        $posted = $this->postJson('/api/purchase-returns/'.$returnUlid.'/post')->assertOk();
        $posted->assertJsonPath('status', 'posted');
        $this->assertTrue(AuditLog::query()->where('event', 'PURCHASE_RETURN_POSTED')->where('resource_ulid', $returnUlid)->exists());

        $movement = StockMovement::query()->where('movement_type', 'purchase_return')->firstOrFail();
        $this->assertSame('-24.000000', (string) $movement->quantity);
        $this->assertSame('100.0000', (string) $movement->unit_cost);
        $this->assertSame('purchase_return', $movement->reference_type);
        $this->assertSame($returnUlid, $movement->reference_ulid);
        $this->assertSame($lineUlid, $movement->reference_line_ulid);
        $this->assertSame('purchase_return:'.$returnUlid.':'.$lineUlid, $movement->idempotency_key);

        $balance = StockBalance::query()->firstOrFail();
        $this->assertSame('24.000000', (string) $balance->quantity);
        $this->assertSame('100.0000', (string) $balance->average_cost);
        $this->assertSame('2400.0000', (string) $balance->stock_value);

        $this->postJson('/api/purchase-returns/'.$returnUlid.'/post')->assertOk();
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'purchase_return')->count());

        $this->patchJson('/api/purchase-returns/'.$returnUlid, [
            'notes' => 'Nope',
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->postJson('/api/purchase-returns/'.$returnUlid.'/lines', [
            'purchase_line_ulid' => $purchaseLineUlid,
            'quantity' => '0.500000',
        ])->assertStatus(422);

        $purchase = PurchaseInvoice::query()->where('ulid', $purchaseUlid)->firstOrFail();
        $this->assertSame('posted', $purchase->status->value);
        $this->assertSame('4750.0000', (string) $purchase->grand_total);
    }

    public function test_partial_multiple_returns_and_over_return_rejected(): void
    {
        $this->signInOwner('pr-multi')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PR-M',
            'name' => 'Multi Return Supplier',
        ])->assertCreated()->json('ulid');
        $productUlid = $this->postJson('/api/products', [
            'name' => 'Multi Return Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $purchaseUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $purchaseLineUlid = $this->postJson('/api/purchases/'.$purchaseUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '5.0000',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchases/'.$purchaseUlid.'/post')->assertOk();

        $first = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchase-returns/'.$first.'/lines', [
            'purchase_line_ulid' => $purchaseLineUlid,
            'quantity' => '4.000000',
        ])->assertCreated();
        $this->postJson('/api/purchase-returns/'.$first.'/post')->assertOk();

        $this->getJson('/api/purchases/'.$purchaseUlid.'/returnable-lines')
            ->assertOk()
            ->assertJsonPath('data.0.already_returned_base_quantity', '4.000000')
            ->assertJsonPath('data.0.remaining_returnable_base_quantity', '6.000000');

        $second = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchase-returns/'.$second.'/lines', [
            'purchase_line_ulid' => $purchaseLineUlid,
            'quantity' => '6.000000',
        ])->assertCreated();
        $this->postJson('/api/purchase-returns/'.$second.'/post')->assertOk();

        $this->assertSame('0.000000', (string) StockBalance::query()->firstOrFail()->quantity);
        $this->assertSame('5.0000', (string) StockBalance::query()->firstOrFail()->average_cost);

        $third = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchase-returns/'.$third.'/lines', [
            'purchase_line_ulid' => $purchaseLineUlid,
            'quantity' => '1.000000',
        ])->assertStatus(422);
    }

    public function test_validations_isolation_stock_and_batch(): void
    {
        $this->signInOwner('pr-iso-a')->assertOk();
        $warehouseA = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $supplierA = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PRA',
            'name' => 'Supplier A',
        ])->assertCreated()->json('ulid');

        $productA = $this->postJson('/api/products', [
            'name' => 'Tracked Return Product',
            'base_unit_ulid' => $pcs,
            'track_batch' => true,
            'track_expiry' => true,
        ])->assertCreated()->json('ulid');

        $purchaseA = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierA,
            'warehouse_ulid' => $warehouseA,
        ])->assertCreated()->json('ulid');

        $lineA = $this->postJson('/api/purchases/'.$purchaseA.'/lines', [
            'product_ulid' => $productA,
            'unit_ulid' => $pcs,
            'quantity' => '5.000000',
            'unit_cost' => '2.0000',
            'batch_number' => 'BATCH-1',
            'expiry_date' => '2027-06-01',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchases/'.$purchaseA.'/post')->assertOk();

        // Drain stock to zero via a full return, then attempt another return for stock insufficiency
        $drain = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseA,
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchase-returns/'.$drain.'/lines', [
            'purchase_line_ulid' => $lineA,
            'quantity' => '5.000000',
            'batch_number' => 'BATCH-1',
            'expiry_date' => '2027-06-01',
        ])->assertCreated();
        $this->postJson('/api/purchase-returns/'.$drain.'/post')->assertOk();
        $this->assertSame('0.000000', (string) StockBalance::query()->firstOrFail()->quantity);

        // New purchase into same warehouse so we can test insufficient stock on a different product path:
        // create second purchase, return against it after manually zeroing would over-return first.
        // Instead: post a return draft while stock is zero by creating another purchase and returning more than stock.
        $purchaseB = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierA,
            'warehouse_ulid' => $warehouseA,
        ])->assertCreated()->json('ulid');
        $lineB = $this->postJson('/api/purchases/'.$purchaseB.'/lines', [
            'product_ulid' => $productA,
            'unit_ulid' => $pcs,
            'quantity' => '3.000000',
            'unit_cost' => '2.0000',
            'batch_number' => 'BATCH-2',
            'expiry_date' => '2027-07-01',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchases/'.$purchaseB.'/post')->assertOk();
        // Balance is now 3. Return requires batch.
        $ret = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseB,
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchase-returns/'.$ret.'/lines', [
            'purchase_line_ulid' => $lineB,
            'quantity' => '1.000000',
        ])->assertCreated(); // inherits batch from purchase line

        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'inactive']);
        $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseB,
            'warehouse_ulid' => $warehouseA,
        ])->assertStatus(422);
        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'active']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('pr-iso-b')->assertOk();
        $warehouseB = $this->sessionWarehouseUlid();
        $supplierB = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PRB',
            'name' => 'Supplier B',
        ])->assertCreated()->json('ulid');

        $this->getJson('/api/purchase-returns/'.$ret)->assertNotFound();
        $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseA,
            'warehouse_ulid' => $warehouseB,
        ])->assertNotFound();

        $foreignPurchase = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierB,
            'warehouse_ulid' => $warehouseB,
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchases/'.$foreignPurchase.'/lines', [
            'product_ulid' => $this->postJson('/api/products', [
                'name' => 'Tenant B Product',
                'base_unit_ulid' => $this->unitUlid('PCS'),
            ])->assertCreated()->json('ulid'),
            'unit_ulid' => $this->unitUlid('PCS'),
            'quantity' => '2.000000',
            'unit_cost' => '1.0000',
        ])->assertCreated();
        $this->postJson('/api/purchases/'.$foreignPurchase.'/post')->assertOk();

        $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $foreignPurchase,
            'warehouse_ulid' => $warehouseA,
        ])->assertNotFound();
    }

    public function test_posting_rolls_back_when_stock_insufficient_and_line_delete(): void
    {
        $this->signInOwner('pr-roll')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PRR',
            'name' => 'Rollback Supplier',
        ])->assertCreated()->json('ulid');
        $productUlid = $this->postJson('/api/products', [
            'name' => 'Rollback Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $purchaseUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');
        $lineUlid = $this->postJson('/api/purchases/'.$purchaseUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '4.000000',
            'unit_cost' => '10.0000',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/purchases/'.$purchaseUlid.'/post')->assertOk();

        // Move stock out of warehouse by posting a full return elsewhere pattern:
        // Zero balance by posting a return, then create another return draft against remaining
        // purchase qty (none left returnable). Instead: reduce stock via second product purchase
        // then manually set balance lower to simulate stock moved elsewhere.
        StockBalance::query()->update(['quantity' => '1.000000', 'stock_value' => '10.0000']);

        $returnUlid = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
        ])->assertCreated()->json('ulid');

        $line = $this->postJson('/api/purchase-returns/'.$returnUlid.'/lines', [
            'purchase_line_ulid' => $lineUlid,
            'quantity' => '2.000000',
        ])->assertCreated();
        $returnLineUlid = $line->json('ulid');

        $this->deleteJson('/api/purchase-returns/'.$returnUlid.'/lines/'.$returnLineUlid)->assertOk();
        $this->postJson('/api/purchase-returns/'.$returnUlid.'/lines', [
            'purchase_line_ulid' => $lineUlid,
            'quantity' => '2.000000',
        ])->assertCreated();

        $this->postJson('/api/purchase-returns/'.$returnUlid.'/post')->assertStatus(422);
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'purchase_return')->count());
        $this->getJson('/api/purchase-returns/'.$returnUlid)->assertOk()->assertJsonPath('status', 'draft');
        $this->assertSame('1.000000', (string) StockBalance::query()->firstOrFail()->quantity);
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

        if ($code === 'CTN') {
            return $this->postJson('/api/units', [
                'code' => 'CTN',
                'name' => 'Carton',
                'symbol' => 'CTN',
                'allows_decimal' => false,
            ])->assertCreated()->json('ulid');
        }

        $this->fail('Missing unit '.$code);
    }
}
