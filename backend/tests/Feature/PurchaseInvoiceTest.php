<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseInvoice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_draft_lifecycle_totals_conversion_and_posting(): void
    {
        $this->signInOwner('pur-life')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $ctn = $this->unitUlid('CTN');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PUR-1',
            'name' => 'Purchase Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Carton Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $invoice = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'supplier_invoice_number' => 'SI-100',
            'freight_amount' => '50.0000',
            'other_charges' => '10.0000',
        ])->assertCreated();

        $invoiceUlid = $invoice->json('ulid');
        $invoice->assertJsonPath('status', 'draft');
        $invoice->assertJsonPath('document_number', 'PUR-000001');
        $this->assertNoInternalIds($invoice->json());
        $this->assertTrue(AuditLog::query()->where('event', 'PURCHASE_CREATED')->where('resource_ulid', $invoiceUlid)->exists());

        $this->patchJson('/api/purchases/'.$invoiceUlid, [
            'notes' => 'Draft notes',
            'freight_amount' => '25.0000',
        ])->assertOk()
            ->assertJsonPath('notes', 'Draft notes')
            ->assertJsonPath('freight_amount', '25.0000');

        // 2 cartons @ 2400, factor 24 => base 48 @ inventory cost 100
        $line = $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $ctn,
            'quantity' => '2.000000',
            'conversion_factor' => '24.00000000',
            'unit_cost' => '2400.0000',
            'discount_amount' => '100.0000',
            'tax_amount' => '50.0000',
            'supplier_product_code' => 'VENDOR-SKU-1',
        ])->assertCreated();

        $lineUlid = $line->json('ulid');
        $line->assertJsonPath('base_quantity', '48.000000');
        $line->assertJsonPath('line_total', '4750.0000'); // 2*2400 - 100 + 50
        $this->assertNoInternalIds($line->json());

        $show = $this->getJson('/api/purchases/'.$invoiceUlid)->assertOk();
        $show->assertJsonPath('subtotal', '4800.0000');
        $show->assertJsonPath('discount_amount', '100.0000');
        $show->assertJsonPath('tax_amount', '50.0000');
        // grand = sum(line_total) + freight + other = 4750 + 25 + 10
        $show->assertJsonPath('grand_total', '4785.0000');
        $this->assertNoInternalIds($show->json());

        $this->patchJson('/api/purchases/'.$invoiceUlid.'/lines/'.$lineUlid, [
            'quantity' => '2.000000',
            'conversion_factor' => '24.00000000',
            'unit_cost' => '2400.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
        ])->assertOk()->assertJsonPath('line_total', '4800.0000');

        $secondProduct = $this->postJson('/api/products', [
            'name' => 'Piece Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $secondProduct,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '5.0000',
        ])->assertCreated();

        $posted = $this->postJson('/api/purchases/'.$invoiceUlid.'/post')->assertOk();
        $posted->assertJsonPath('status', 'posted');
        $this->assertTrue(AuditLog::query()->where('event', 'PURCHASE_POSTED')->where('resource_ulid', $invoiceUlid)->exists());

        $this->assertSame(2, StockMovement::query()->count());
        $purchaseMovements = StockMovement::query()->where('movement_type', 'purchase')->orderBy('id')->get();
        $this->assertCount(2, $purchaseMovements);

        $cartonMovement = $purchaseMovements->firstWhere('reference_line_ulid', $lineUlid);
        $this->assertNotNull($cartonMovement);
        $this->assertSame('48.000000', (string) $cartonMovement->quantity);
        $this->assertSame('100.0000', (string) $cartonMovement->unit_cost);
        $this->assertSame('purchase_invoice', $cartonMovement->reference_type);
        $this->assertSame($invoiceUlid, $cartonMovement->reference_ulid);
        $this->assertSame('purchase:'.$invoiceUlid.':'.$lineUlid, $cartonMovement->idempotency_key);

        $balance = StockBalance::query()
            ->where('product_id', Product::query()->where('ulid', $productUlid)->value('id'))
            ->firstOrFail();
        $this->assertSame('48.000000', (string) $balance->quantity);
        $this->assertSame('100.0000', (string) $balance->average_cost);

        $link = ProductSupplier::query()
            ->where('product_id', Product::query()->where('ulid', $productUlid)->value('id'))
            ->where('supplier_id', Supplier::query()->where('ulid', $supplierUlid)->value('id'))
            ->first();
        $this->assertNotNull($link);
        $this->assertTrue($link->is_active);
        $this->assertFalse($link->is_primary);
        $this->assertSame('VENDOR-SKU-1', $link->supplier_product_code);

        $this->postJson('/api/purchases/'.$invoiceUlid.'/post')->assertOk();
        $this->assertSame(2, StockMovement::query()->count());

        $this->patchJson('/api/purchases/'.$invoiceUlid, ['notes' => 'Nope'])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');
        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);
        $this->deleteJson('/api/purchases/'.$invoiceUlid.'/lines/'.$lineUlid)->assertStatus(422);
    }

    public function test_weighted_average_and_zero_stock_purchase(): void
    {
        $this->signInOwner('pur-avg')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-AVG',
            'name' => 'Avg Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Avg Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $first = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$first.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '10.0000',
        ])->assertCreated();
        $this->postJson('/api/purchases/'.$first.'/post')->assertOk();

        $balance = StockBalance::query()->firstOrFail();
        $this->assertSame('10.000000', (string) $balance->quantity);
        $this->assertSame('10.0000', (string) $balance->average_cost);

        $second = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$second.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '20.0000',
        ])->assertCreated();
        $this->postJson('/api/purchases/'.$second.'/post')->assertOk();

        $balance->refresh();
        $this->assertSame('20.000000', (string) $balance->quantity);
        $this->assertSame('15.0000', (string) $balance->average_cost);
        $this->assertSame('300.0000', (string) $balance->stock_value);
    }

    public function test_line_delete_and_validations_and_isolation(): void
    {
        $this->signInOwner('pur-iso-a')->assertOk();
        $warehouseA = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierA = $this->postJson('/api/suppliers', [
            'code' => 'SUP-A',
            'name' => 'Supplier A',
        ])->assertCreated()->json('ulid');

        $productA = $this->postJson('/api/products', [
            'name' => 'Tracked Product',
            'base_unit_ulid' => $pcs,
            'track_batch' => true,
            'track_expiry' => true,
        ])->assertCreated()->json('ulid');

        $invoiceA = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierA,
            'warehouse_ulid' => $warehouseA,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceA.'/lines', [
            'product_ulid' => $productA,
            'unit_ulid' => $pcs,
            'quantity' => '0',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);

        $this->postJson('/api/purchases/'.$invoiceA.'/lines', [
            'product_ulid' => $productA,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);

        $lineUlid = $this->postJson('/api/purchases/'.$invoiceA.'/lines', [
            'product_ulid' => $productA,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '1.0000',
            'batch_number' => 'B1',
            'expiry_date' => '2030-01-01',
        ])->assertCreated()->json('ulid');

        $this->deleteJson('/api/purchases/'.$invoiceA.'/lines/'.$lineUlid)->assertOk();
        $this->postJson('/api/purchases/'.$invoiceA.'/post')->assertStatus(422);
        $this->assertSame(0, StockMovement::query()->count());

        Supplier::query()->where('ulid', $supplierA)->update(['is_active' => false]);
        $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierA,
            'warehouse_ulid' => $warehouseA,
        ])->assertStatus(422);
        Supplier::query()->where('ulid', $supplierA)->update(['is_active' => true]);

        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'inactive']);
        $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierA,
            'warehouse_ulid' => $warehouseA,
        ])->assertStatus(422);
        Warehouse::query()->where('ulid', $warehouseA)->update(['status' => 'active']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('pur-iso-b')->assertOk();
        $warehouseB = $this->sessionWarehouseUlid();
        $supplierB = $this->postJson('/api/suppliers', [
            'code' => 'SUP-B',
            'name' => 'Supplier B',
        ])->assertCreated()->json('ulid');

        $this->getJson('/api/purchases/'.$invoiceA)->assertNotFound();
        $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierA,
            'warehouse_ulid' => $warehouseB,
        ])->assertNotFound();
        $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierB,
            'warehouse_ulid' => $warehouseA,
        ])->assertNotFound();

        $invoiceB = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierB,
            'warehouse_ulid' => $warehouseB,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceB.'/lines', [
            'product_ulid' => $productA,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '1.0000',
            'batch_number' => 'X',
            'expiry_date' => '2030-01-01',
        ])->assertNotFound();
    }

    public function test_inactive_product_rejected_and_post_rolls_back(): void
    {
        $this->signInOwner('pur-roll')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-ROLL',
            'name' => 'Roll Supplier',
        ])->assertCreated()->json('ulid');

        $active = $this->postJson('/api/products', [
            'name' => 'Active Line',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $inactive = $this->postJson('/api/products', [
            'name' => 'Will Deactivate',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $invoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $active,
            'unit_ulid' => $pcs,
            'quantity' => '2.000000',
            'unit_cost' => '3.0000',
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $inactive,
            'unit_ulid' => $pcs,
            'quantity' => '4.000000',
            'unit_cost' => '5.0000',
        ])->assertCreated();

        Product::query()->where('ulid', $inactive)->update([
            'is_active' => false,
            'status' => 'discontinued',
        ]);

        $this->postJson('/api/purchases/'.$invoiceUlid.'/post')->assertStatus(422);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
        $this->assertSame('draft', PurchaseInvoice::query()->where('ulid', $invoiceUlid)->firstOrFail()->status->value);

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $inactive,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '1.0000',
        ])->assertStatus(422);
    }

    public function test_list_filters_and_primary_supplier_not_replaced(): void
    {
        $this->signInOwner('pur-list')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $primary = $this->postJson('/api/suppliers', [
            'code' => 'SUP-P',
            'name' => 'Primary',
        ])->assertCreated()->json('ulid');
        $other = $this->postJson('/api/suppliers', [
            'code' => 'SUP-O',
            'name' => 'Other',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Linked Product',
            'base_unit_ulid' => $pcs,
            'primary_supplier_ulid' => $primary,
            'supplier_product_code' => 'PRIMARY-CODE',
        ])->assertCreated()->json('ulid');

        $invoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $other,
            'warehouse_ulid' => $warehouseUlid,
            'invoice_date' => '2026-09-01',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '9.0000',
            'supplier_product_code' => 'OTHER-CODE',
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$invoiceUlid.'/post')->assertOk();

        $product = $this->getJson('/api/products/'.$productUlid)->assertOk();
        $product->assertJsonPath('primary_supplier.ulid', $primary);

        $links = ProductSupplier::query()
            ->where('product_id', Product::query()->where('ulid', $productUlid)->value('id'))
            ->get();
        $this->assertCount(2, $links);
        $this->assertTrue($links->contains(fn ($row) => $row->is_primary && $row->supplier_product_code === 'PRIMARY-CODE'));
        $this->assertTrue($links->contains(fn ($row) => ! $row->is_primary && $row->supplier_product_code === 'OTHER-CODE'));

        $list = $this->getJson('/api/purchases?status=posted&supplier_ulid='.$other.'&date_from=2026-09-01&date_to=2026-09-01')->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $this->assertNoInternalIds($list->json());
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
