<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseInvoice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_draft_lifecycle_totals_conversion_and_posting(): void
    {
        $this->signInOwner('pur-life')->assertOk();
        $this->configurePurchaseClearing();
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
        $show->assertJsonPath('balance_payable', '4785.0000');
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

        $invoiceId = PurchaseInvoice::query()->where('ulid', $invoiceUlid)->value('id');
        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE)
            ->where('document_id', $invoiceId)
            ->with('lines')
            ->sole();
        $this->assertSame('posted', $journal->status->value);
        $this->assertCount(2, $journal->lines);
        $journalDebit = $journal->lines->reduce(
            fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->debit, 4),
            '0.0000',
        );
        $journalCredit = $journal->lines->reduce(
            fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->credit, 4),
            '0.0000',
        );
        $this->assertSame((string) $posted->json('grand_total'), $journalDebit);
        $this->assertSame($journalDebit, $journalCredit);
        $this->assertTrue($journal->lines->contains(
            fn (JournalLine $line): bool => $line->supplier_id !== null
                && bccomp((string) $line->credit, $journalCredit, 4) === 0
        ));

        // Posting is idempotent: no duplicate stock movements or accounting journals.
        $this->postJson('/api/purchases/'.$invoiceUlid.'/post')->assertOk();
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE)
                ->where('document_id', $invoiceId)
                ->count(),
        );

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
        $this->configurePurchaseClearing();
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
        $this->configurePurchaseClearing();
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
        $this->configurePurchaseClearing();
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
        $this->configurePurchaseClearing();
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
        $this->configurePurchaseClearing();
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

    public function test_purchase_entry_fields_persist_and_server_recalculates_pcts(): void
    {
        $this->signInOwner('pur-entry')->assertOk();
        $this->configurePurchaseClearing();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-ENTRY',
            'name' => 'Entry Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Entry Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $invoice = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'po_number' => 'PO-77',
            'invoice_type' => 'tax_gst',
            'currency_code' => 'PKR',
            'calculation_method' => 'gst_on_trade',
            'default_sales_tax_pct' => '18',
            'default_price_type' => 'trade',
            'brand_label' => 'Nestle',
            'freight_amount' => '100.0000',
            'loading_amount' => '20.0000',
            'other_charges' => '5.0000',
            'other_discount' => '10.0000',
            'trade_offer' => '5.0000',
            'advance_tax_amount' => '2.0000',
            'round_off' => '0.5000',
            'tax_type' => 'standard',
            'payment_terms' => 'credit',
            'notes' => 'Entry notes',
        ])->assertCreated();

        $invoiceUlid = $invoice->json('ulid');
        $invoice->assertJsonPath('po_number', 'PO-77')
            ->assertJsonPath('loading_amount', '20.0000')
            ->assertJsonPath('brand_label', 'Nestle')
            ->assertJsonPath('payment_terms', 'credit');

        // 10 * 100 = 1000; trade 10% => 900; regular 5% => 855; tax 18% => 153.9; further 1% => 8.55
        $line = $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '100.0000',
            'brand_label' => 'Nestle',
            'hs_code' => '1905',
            'pack_size' => '24',
            'qty_ctn' => '1.000000',
            'free_pcs' => '2.000000',
            'price_type' => 'trade',
            'mrp' => '150.0000',
            'trade_disc_pct' => '10',
            'regular_disc_pct' => '5',
            'special_disc_pct' => '0',
            'tax_pct' => '18',
            'further_tax_pct' => '1',
            'discount_amount' => '9999.0000',
            'tax_amount' => '9999.0000',
        ])->assertCreated();

        $line->assertJsonPath('discount_amount', '145.0000')
            ->assertJsonPath('tax_amount', '153.9000')
            ->assertJsonPath('further_tax_amount', '8.5500')
            ->assertJsonPath('line_total', '1017.4500')
            ->assertJsonPath('hs_code', '1905')
            ->assertJsonPath('free_pcs', '2.000000');

        $show = $this->getJson('/api/purchases/'.$invoiceUlid)->assertOk();
        $show->assertJsonPath('subtotal', '1000.0000')
            ->assertJsonPath('discount_amount', '145.0000')
            ->assertJsonPath('tax_amount', '153.9000')
            ->assertJsonPath('further_tax_amount', '8.5500');
        // net lines 1017.45 + freight/loading/other - discounts + advance + round_off
        $this->assertSame('1129.9500', $show->json('grand_total'));
        $this->assertNoInternalIds($show->json());
    }

    public function test_posting_syncs_retail_price_tax_and_free_pcs_stock(): void
    {
        $this->signInOwner('pur-sync')->assertOk();
        $this->configurePurchaseClearing();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-SYNC',
            'name' => 'Sync Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Sync Product',
            'base_unit_ulid' => $pcs,
            'tax_percent' => '5',
            'prices' => [
                ['price_type' => 'retail', 'amount' => '90.0000'],
            ],
        ])->assertCreated()->json('ulid');

        $invoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '100.0000',
            'free_pcs' => '2.000000',
            'mrp' => '175.5000',
            'tax_pct' => '18',
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$invoiceUlid.'/post')->assertOk();

        $product = $this->getJson('/api/products/'.$productUlid)->assertOk();
        $this->assertSame('18.00000000', $product->json('tax_percent'));
        $retail = collect($product->json('prices'))->firstWhere('price_type', 'retail');
        $this->assertNotNull($retail);
        $this->assertSame('175.5000', $retail['amount']);

        $productId = Product::query()->where('ulid', $productUlid)->value('id');
        $balance = StockBalance::query()->where('product_id', $productId)->firstOrFail();
        // 10 paid + 2 free = 12 base units; paid value 1000 diluted → 83.3333 avg
        $this->assertSame('12.000000', (string) $balance->quantity);
        $this->assertSame('83.3333', (string) $balance->average_cost);

        $movement = StockMovement::query()
            ->where('product_id', $productId)
            ->where('movement_type', 'purchase')
            ->firstOrFail();
        $this->assertSame('12.000000', (string) $movement->quantity);
        $this->assertSame('83.3333', (string) $movement->unit_cost);
    }

    public function test_gst_apply_on_mrp_without_gst_uses_mrp_base(): void
    {
        $this->signInOwner('pur-gst-mrp')->assertOk();
        $this->configurePurchaseClearing();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-GST-MRP',
            'name' => 'GST MRP Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'GST MRP Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $invoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'calculation_method' => 'mrp_ex_gst',
        ])->assertCreated()->json('ulid');

        // Trade 520, MRP 500, qty 10, tax 18% → GST on MRP = 10*500*0.18 = 900 (not on trade)
        $line = $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '10.000000',
            'unit_cost' => '520.0000',
            'mrp' => '500.0000',
            'tax_pct' => '18',
            'discount_amount' => '0',
            'tax_amount' => '0',
        ])->assertCreated();

        $line->assertJsonPath('tax_amount', '900.0000')
            ->assertJsonPath('line_total', '6100.0000'); // 5200 + 900
    }


    public function test_purchase_posting_requires_clearing_account_and_rolls_back(): void
    {
        $this->signInOwner('pur-accounting-required')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-ACC-REQ',
            'name' => 'Accounting Required Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Accounting Required Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $invoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '2.000000',
            'unit_cost' => '10.0000',
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$invoiceUlid.'/post')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'PURCHASE_CLEARING_ACCOUNT_REQUIRED');

        $this->assertSame(
            'draft',
            PurchaseInvoice::query()->where('ulid', $invoiceUlid)->firstOrFail()->status->value,
        );
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE)
                ->count(),
        );
    }

    public function test_purchase_invoice_is_bound_to_active_warehouse_context(): void
    {
        $this->signInOwner('pur-warehouse-context')->assertOk();
        $this->configurePurchaseClearing();

        $me = $this->getJson('/api/auth/me')->assertOk();
        $activeWarehouseUlid = (string) $me->json('warehouse.ulid');
        $branchUlid = (string) $me->json('branch.ulid');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-WH-CTX',
            'name' => 'Warehouse Context Supplier',
        ])->assertCreated()->json('ulid');

        $otherWarehouseUlid = $this->postJson('/api/warehouses', [
            'code' => 'WH-OTHER',
            'name' => 'Other Same Branch Warehouse',
            'branch_ulid' => $branchUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $otherWarehouseUlid,
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.warehouse_ulid.0',
                'Purchase warehouse must match the active warehouse context.',
            );

        $invoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $activeWarehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->patchJson('/api/purchases/'.$invoiceUlid, [
            'warehouse_ulid' => $otherWarehouseUlid,
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.warehouse_ulid.0',
                'Purchase warehouse must match the active warehouse context.',
            );

        $invoice = PurchaseInvoice::query()->where('ulid', $invoiceUlid)->firstOrFail();
        $otherWarehouseId = Warehouse::query()->where('ulid', $otherWarehouseUlid)->value('id');

        $otherWarehouseInvoice = $invoice->replicate();
        $otherWarehouseInvoice->ulid = null;
        $otherWarehouseInvoice->document_number = 'PUR-CTX-OTHER';
        $otherWarehouseInvoice->warehouse_id = $otherWarehouseId;
        $otherWarehouseInvoice->save();

        $this->getJson('/api/purchases/'.$otherWarehouseInvoice->ulid)->assertNotFound();

        $list = $this->getJson('/api/purchases')->assertOk();
        $list->assertJsonMissing(['ulid' => $otherWarehouseInvoice->ulid]);
        $list->assertJsonFragment(['ulid' => $invoiceUlid]);
    }


    public function test_purchase_invoice_links_to_purchase_order_and_order_lines(): void
    {
        $this->signInOwner('pur-po-link')->assertOk();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PO-LINK',
            'name' => 'PO Link Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'PO Link Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $order = $this->postJson('/api/purchase-orders', [
            'supplier_ulid' => $supplierUlid,
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $pcs,
                'quantity' => '5.000000',
                'unit_price' => '100.0000',
                'discount_amount' => '10.0000',
            ]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'purchase_order_ulid' => $order->json('ulid'),
            'po_number' => 'CLIENT-SHOULD-NOT-WIN',
        ])->assertCreated();

        $invoice->assertJsonPath('purchase_order.ulid', $order->json('ulid'))
            ->assertJsonPath('purchase_order.document_number', $order->json('document_number'))
            ->assertJsonPath('po_number', $order->json('document_number'));

        $invoiceUlid = $invoice->json('ulid');
        $orderLineUlid = $order->json('items.0.ulid');

        $line = $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'purchase_order_line_ulid' => $orderLineUlid,
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '2.000000',
            'conversion_factor' => '1.00000000',
            'unit_cost' => '100.0000',
            'discount_amount' => '4.0000',
        ])->assertCreated();

        $line->assertJsonPath('purchase_order_line_ulid', $orderLineUlid);

        $stored = PurchaseInvoice::query()
            ->with('lines')
            ->where('ulid', $invoiceUlid)
            ->firstOrFail();

        $this->assertNotNull($stored->purchase_order_id);
        $this->assertNotNull($stored->lines->firstOrFail()->purchase_order_line_id);

        $show = $this->getJson('/api/purchases/'.$invoiceUlid)->assertOk();
        $show->assertJsonPath('purchase_order.ulid', $order->json('ulid'))
            ->assertJsonPath('lines.0.purchase_order_line_ulid', $orderLineUlid);
        $this->assertNoInternalIds($show->json());

        $otherProductUlid = $this->postJson('/api/products', [
            'name' => 'Wrong PO Link Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$invoiceUlid.'/lines', [
            'purchase_order_line_ulid' => $orderLineUlid,
            'product_ulid' => $otherProductUlid,
            'unit_ulid' => $pcs,
            'quantity' => '1.000000',
            'unit_cost' => '10.0000',
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.product_ulid.0',
                'Product must match the linked Purchase Order line.',
            );

        $this->patchJson('/api/purchases/'.$invoiceUlid.'/lines/'.$line->json('ulid'), [
            'product_ulid' => $otherProductUlid,
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.product_ulid.0',
                'Product must match the linked Purchase Order line.',
            );

        $this->patchJson('/api/purchases/'.$invoiceUlid, [
            'po_number' => 'MUTATED-PO-NUMBER',
        ])->assertOk()
            ->assertJsonPath('po_number', $order->json('document_number'));

        $otherSupplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PO-LINK-OTHER',
            'name' => 'Other PO Link Supplier',
        ])->assertCreated()->json('ulid');

        $this->patchJson('/api/purchases/'.$invoiceUlid, [
            'supplier_ulid' => $otherSupplierUlid,
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.supplier_ulid.0',
                'Purchase Invoice supplier must match the linked Purchase Order.',
            );
    }


    public function test_partial_purchase_order_receipts_block_over_receipt_and_close_when_complete(): void
    {
        $this->signInOwner('pur-po-receive')->assertOk();
        $this->configurePurchaseClearing();
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PO-RECEIVE',
            'name' => 'PO Receive Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'PO Receive Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $order = $this->postJson('/api/purchase-orders', [
            'supplier_ulid' => $supplierUlid,
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $pcs,
                'quantity' => '5.000000',
                'unit_price' => '100.0000',
            ]],
        ])->assertCreated();

        $orderUlid = (string) $order->json('ulid');
        $orderLineUlid = (string) $order->json('items.0.ulid');

        $firstInvoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'purchase_order_ulid' => $orderUlid,
        ])->assertCreated()->json('ulid');

        $firstLine = $this->postJson('/api/purchases/'.$firstInvoiceUlid.'/lines', [
            'purchase_order_line_ulid' => $orderLineUlid,
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '2.000000',
            'conversion_factor' => '9.00000000',
            'unit_cost' => '100.0000',
        ])->assertCreated();

        $firstLine->assertJsonPath('conversion_factor', '1.00000000')
            ->assertJsonPath('base_quantity', '2.000000');

        $this->postJson('/api/purchases/'.$firstInvoiceUlid.'/post')->assertOk();

        $this->getJson('/api/purchase-orders/'.$orderUlid)
            ->assertOk()
            ->assertJsonPath('status', 'open');

        $secondInvoiceUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'purchase_order_ulid' => $orderUlid,
        ])->assertCreated()->json('ulid');

        $secondLine = $this->postJson('/api/purchases/'.$secondInvoiceUlid.'/lines', [
            'purchase_order_line_ulid' => $orderLineUlid,
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => '4.000000',
            'unit_cost' => '100.0000',
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$secondInvoiceUlid.'/post')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.lines.0',
                'Received quantity cannot exceed the remaining Purchase Order quantity.',
            );

        $this->assertSame(
            'draft',
            PurchaseInvoice::query()
                ->where('ulid', $secondInvoiceUlid)
                ->firstOrFail()
                ->status
                ->value,
        );

        $this->patchJson('/api/purchases/'.$secondInvoiceUlid.'/lines/'.$secondLine->json('ulid'), [
            'quantity' => '3.000000',
        ])->assertOk()
            ->assertJsonPath('base_quantity', '3.000000');

        $this->postJson('/api/purchases/'.$secondInvoiceUlid.'/post')->assertOk();

        $this->getJson('/api/purchase-orders/'.$orderUlid)
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'PURCHASE_ORDER_CLOSED')
                ->where('resource_ulid', $orderUlid)
                ->exists(),
        );

        $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
            'purchase_order_ulid' => $orderUlid,
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.fields.purchase_order_ulid.0',
                'Only open Purchase Orders can receive new Purchase Invoices.',
            );
    }

    private function configurePurchaseClearing(): void
    {
        $tenantContext = app(TenantContext::class);
        $inventoryType = AccountType::query()
            ->forTenant($tenantContext->tenantId())
            ->where('code', '0060')
            ->firstOrFail();

        $clearing = Account::query()->firstOrCreate(
            [
                'tenant_id' => $tenantContext->tenantId(),
                'code' => 'PUR-CLEAR',
            ],
            [
                'name' => 'Purchase Clearing',
                'account_type_id' => $inventoryType->id,
                'is_active' => true,
                'created_by' => $tenantContext->userId(),
            ],
        );

        BusinessSetting::query()
            ->forTenant($tenantContext->tenantId())
            ->update(['purchase_clearing_account_id' => $clearing->id]);
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
