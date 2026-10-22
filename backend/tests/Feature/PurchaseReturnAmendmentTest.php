<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseReturnAmendmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_purchase_return_can_be_amended_repeatedly_without_mutating_original_rows(): void
    {
        $this->signInOwner('purchase-return-amend-basic')->assertOk();
        $this->configurePurchaseClearing();

        [$returnUlid, $purchaseLineUlid, $productUlid] = $this->postedReturn(
            'Purchase Return Amend Product',
            '10.000000',
            '2.000000',
            '10.0000',
        );

        $document = PurchaseReturn::query()->where('ulid', $returnUlid)->firstOrFail();
        $originalLine = PurchaseReturnLine::query()
            ->where('purchase_return_id', $document->id)
            ->firstOrFail();
        $originalJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_RETURN)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $first = $this->patchJson('/api/purchase-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Increase returned quantity',
            'reason' => 'Quantity correction',
            'lines' => [[
                'purchase_line_ulid' => $purchaseLineUlid,
                'quantity' => '3.000000',
            ]],
        ], $this->idem('purchase-return-amend-r1'))
            ->assertOk()
            ->assertJsonPath('ulid', $returnUlid)
            ->assertJsonPath('grand_total', '30.0000')
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('is_amended', true)
            ->assertJsonPath('lines.0.quantity', '3.000000');

        $this->assertSame('20.0000', (string) $document->fresh()->grand_total);
        $this->assertSame('2.000000', (string) $originalLine->fresh()->quantity);
        $this->assertSame('7.000000', $this->stockFor($productUlid));

        $reversal = JournalEntry::query()
            ->where('reverses_journal_entry_id', $originalJournal->id)
            ->where('document_type', $originalJournal->document_type.'_reversal')
            ->with('lines')
            ->firstOrFail();

        $amendmentJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_RETURN_AMENDMENT)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertBalanced($reversal);
        $this->assertBalanced($amendmentJournal);

        $this->patchJson('/api/purchase-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Idempotent retry',
            'lines' => [[
                'purchase_line_ulid' => $purchaseLineUlid,
                'quantity' => '3.000000',
            ]],
        ], $this->idem('purchase-return-amend-r1'))
            ->assertOk()
            ->assertJsonPath('revision_number', 1);

        $this->assertSame(
            1,
            DocumentRevision::query()
                ->where('document_type', 'purchase_return')
                ->where('document_ulid', $returnUlid)
                ->count(),
        );

        $this->patchJson('/api/purchase-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Reduce returned quantity',
            'lines' => [[
                'purchase_line_ulid' => $purchaseLineUlid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('purchase-return-amend-r2'))
            ->assertOk()
            ->assertJsonPath('grand_total', '10.0000')
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('lines.0.quantity', '1.000000');

        $this->assertSame('9.000000', $this->stockFor($productUlid));
        $this->assertSame('2.000000', (string) $originalLine->fresh()->quantity);
        $this->assertSame(
            2,
            DocumentRevision::query()
                ->where('document_type', 'purchase_return')
                ->where('document_ulid', $returnUlid)
                ->count(),
        );

        $this->getJson(
            '/api/purchases/'.$document->purchaseInvoice->ulid.'/returnable-lines?exclude_return_ulid='.$returnUlid,
        )
            ->assertOk()
            ->assertJsonPath('data.0.remaining_returnable_base_quantity', '10.000000');

        $this->getJson('/api/purchases/'.$document->purchaseInvoice->ulid.'/returnable-lines')
            ->assertOk()
            ->assertJsonPath('data.0.already_returned_base_quantity', '1.000000')
            ->assertJsonPath('data.0.remaining_returnable_base_quantity', '9.000000');

        $this->assertNoInternalIds($first->json());
    }

    public function test_posted_purchase_return_can_switch_effective_purchase_supplier_and_product(): void
    {
        $this->signInOwner('purchase-return-amend-source-switch')->assertOk();
        $this->configurePurchaseClearing();

        [$returnUlid, , $oldProductUlid] = $this->postedReturn(
            'Original Return Product',
            '5.000000',
            '1.000000',
            '10.0000',
        );

        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-SWITCH',
            'name' => 'Replacement Supplier',
        ])->assertCreated()->json('ulid');

        $newProductUlid = $this->postJson('/api/products', [
            'name' => 'Replacement Return Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $purchaseUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $purchaseLineUlid = $this->postJson('/api/purchases/'.$purchaseUlid.'/lines', [
            'product_ulid' => $newProductUlid,
            'unit_ulid' => $pcs,
            'quantity' => '6.000000',
            'unit_cost' => '12.0000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$purchaseUlid.'/post')->assertOk();

        $response = $this->patchJson('/api/purchase-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Correct purchase source and supplier',
            'purchase_ulid' => $purchaseUlid,
            'warehouse_ulid' => $warehouseUlid,
            'return_date' => now()->toDateString(),
            'lines' => [[
                'purchase_line_ulid' => $purchaseLineUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('purchase-return-amend-source-switch'))
            ->assertOk()
            ->assertJsonPath('original_purchase.ulid', $purchaseUlid)
            ->assertJsonPath('supplier.ulid', $supplierUlid)
            ->assertJsonPath('lines.0.product.ulid', $newProductUlid)
            ->assertJsonPath('lines.0.quantity', '2.000000')
            ->assertJsonPath('grand_total', '24.0000')
            ->assertJsonPath('revision_number', 1);

        $this->assertSame('5.000000', $this->stockFor($oldProductUlid));
        $this->assertSame('4.000000', $this->stockFor($newProductUlid));

        $document = PurchaseReturn::query()->where('ulid', $returnUlid)->firstOrFail();
        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_RETURN_AMENDMENT)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertBalanced($journal);
        $this->assertTrue(
            $journal->lines->contains(
                fn ($line) => $line->supplier_id !== null && bccomp((string) $line->debit, '24.0000', 4) === 0,
            ),
        );

        $this->getJson('/api/purchases/'.$purchaseUlid.'/returnable-lines')
            ->assertOk()
            ->assertJsonPath('data.0.already_returned_base_quantity', '2.000000')
            ->assertJsonPath('data.0.remaining_returnable_base_quantity', '4.000000');

        $this->assertNoInternalIds($response->json());
    }

    public function test_purchase_return_amendment_requires_idempotency_and_is_tenant_isolated(): void
    {
        $this->signInOwner('purchase-return-amend-tenant-a')->assertOk();
        $this->configurePurchaseClearing();

        [$returnUlid, $purchaseLineUlid] = $this->postedReturn(
            'Protected Purchase Return',
            '4.000000',
            '1.000000',
            '5.0000',
        );

        $this->patchJson('/api/purchase-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Missing key',
            'lines' => [[
                'purchase_line_ulid' => $purchaseLineUlid,
                'quantity' => '2.000000',
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->postJson('/api/auth/logout')->assertOk();

        $this->signInOwner('purchase-return-amend-tenant-b')->assertOk();
        $this->configurePurchaseClearing();

        $this->patchJson('/api/purchase-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Cross tenant attempt',
            'lines' => [[
                'purchase_line_ulid' => $purchaseLineUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('purchase-return-amend-cross-tenant'))
            ->assertNotFound();
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function postedReturn(
        string $productName,
        string $purchaseQty,
        string $returnQty,
        string $unitCost,
    ): array {
        $warehouseUlid = $this->sessionWarehouseUlid();
        $pcs = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-'.substr(md5($productName), 0, 8),
            'name' => $productName.' Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => $productName,
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $purchaseUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $purchaseLineUlid = $this->postJson('/api/purchases/'.$purchaseUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $pcs,
            'quantity' => $purchaseQty,
            'unit_cost' => $unitCost,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$purchaseUlid.'/post')->assertOk();

        $returnUlid = $this->postJson('/api/purchase-returns', [
            'purchase_ulid' => $purchaseUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchase-returns/'.$returnUlid.'/lines', [
            'purchase_line_ulid' => $purchaseLineUlid,
            'quantity' => $returnQty,
        ])->assertCreated();

        $this->postJson('/api/purchase-returns/'.$returnUlid.'/post')->assertOk();

        return [$returnUlid, $purchaseLineUlid, $productUlid];
    }

    private function configurePurchaseClearing(): void
    {
        $context = app(TenantContext::class);
        $inventoryType = AccountType::query()
            ->forTenant($context->tenantId())
            ->where('code', '0060')
            ->firstOrFail();

        $clearing = Account::query()->firstOrCreate(
            [
                'tenant_id' => $context->tenantId(),
                'code' => 'PUR-RETURN-AMEND-CLEAR',
            ],
            [
                'name' => 'Purchase Return Amendment Clearing',
                'account_type_id' => $inventoryType->id,
                'is_active' => true,
                'created_by' => $context->userId(),
            ],
        );

        BusinessSetting::query()
            ->forTenant($context->tenantId())
            ->update(['purchase_clearing_account_id' => $clearing->id]);
    }

    private function stockFor(string $productUlid): string
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        return (string) StockBalance::query()
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    private function assertBalanced(JournalEntry $journal): void
    {
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($journal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame($debit, $credit);
    }

    /** @return array<string,string> */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    private function sessionWarehouseUlid(): string
    {
        return (string) $this->getJson('/api/auth/me')->assertOk()->json('warehouse.ulid');
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
