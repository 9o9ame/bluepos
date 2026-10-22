<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockMovementAction;
use App\Authz\PermissionService;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchasePayment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseAmendmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_purchase_can_be_amended_repeatedly_without_mutating_original_rows(): void
    {
        $this->signInOwner('purchase-amend-basic')->assertOk();
        $this->configurePurchaseClearing();

        [$purchaseUlid, $productUlid, $unitUlid] = $this->createPostedPurchase(
            'PUR-AMEND-BASIC',
            '10.000000',
            '10.0000',
        );

        $invoice = PurchaseInvoice::query()->where('ulid', $purchaseUlid)->firstOrFail();
        $originalLine = PurchaseInvoiceLine::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->firstOrFail();

        $first = $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '12.000000',
                'unit_cost' => '10.0000',
            ]],
            'revision_reason' => 'Supplier delivered two additional pieces',
        ], $this->idem('purchase-amend-r1'))
            ->assertOk()
            ->assertJsonPath('ulid', $purchaseUlid)
            ->assertJsonPath('grand_total', '120.0000')
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('is_amended', true)
            ->assertJsonPath('lines.0.quantity', '12.000000');

        $balance = $this->balanceFor($productUlid);
        $this->assertSame('12.000000', (string) $balance->quantity);
        $this->assertSame('10.0000', (string) $balance->average_cost);
        $this->assertSame('120.0000', (string) $balance->stock_value);

        $this->assertSame('100.0000', (string) $invoice->fresh()->grand_total);
        $this->assertSame('10.000000', (string) $originalLine->fresh()->quantity);
        $this->assertSame('10.0000', (string) $originalLine->fresh()->unit_cost);

        $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '12.000000',
                'unit_cost' => '10.0000',
            ]],
            'revision_reason' => 'Retry',
        ], $this->idem('purchase-amend-r1'))
            ->assertOk()
            ->assertJsonPath('revision_number', 1);

        $this->assertSame(
            1,
            DocumentRevision::query()
                ->where('document_type', 'purchase_invoice')
                ->where('document_ulid', $purchaseUlid)
                ->count(),
        );

        $second = $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '12.000000',
                'unit_cost' => '12.0000',
            ]],
            'revision_reason' => 'Supplier corrected the unit cost',
        ], $this->idem('purchase-amend-r2'))
            ->assertOk()
            ->assertJsonPath('grand_total', '144.0000')
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('lines.0.unit_cost', '12.0000');

        $balance->refresh();
        $this->assertSame('12.000000', (string) $balance->quantity);
        $this->assertSame('12.0000', (string) $balance->average_cost);
        $this->assertSame('144.0000', (string) $balance->stock_value);
        $this->assertSame('100.0000', (string) $invoice->fresh()->grand_total);
        $this->assertSame('10.0000', (string) $originalLine->fresh()->unit_cost);

        $this->getJson('/api/purchases/'.$purchaseUlid)
            ->assertOk()
            ->assertJsonPath('grand_total', '144.0000')
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('lines.0.quantity', '12.000000');

        $this->assertSame(
            2,
            StockMovement::query()
                ->where('reference_type', 'purchase_invoice_amendment')
                ->where('reference_ulid', $purchaseUlid)
                ->count(),
        );

        $this->assertNoInternalIds($first->json());
        $this->assertNoInternalIds($second->json());
    }

    public function test_purchase_amendment_requires_explicit_permission(): void
    {
        $this->signInOwner('purchase-amend-permission')->assertOk();
        $this->configurePurchaseClearing();

        [$purchaseUlid, $productUlid, $unitUlid] = $this->createPostedPurchase(
            'PUR-AMEND-PERM',
            '2.000000',
            '20.0000',
        );

        $permission = Permission::query()
            ->where('key', 'purchases.amend_posted')
            ->firstOrFail();
        $membership = app(TenantContext::class)->membership();

        foreach ($membership->roles()->get() as $role) {
            $role->permissions()->detach($permission->id);
        }
        app(PermissionService::class)->clearCache();

        $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '3.000000',
                'unit_cost' => '20.0000',
            ]],
            'revision_reason' => 'Should be denied',
        ], $this->idem('purchase-amend-denied'))
            ->assertForbidden();

        $this->assertSame(0, DocumentRevision::query()->count());
    }

    public function test_purchase_amendment_cannot_reduce_total_below_paid_amount(): void
    {
        $this->signInOwner('purchase-amend-paid')->assertOk();
        $this->configurePurchaseClearing();

        [$purchaseUlid, $productUlid, $unitUlid] = $this->createPostedPurchase(
            'PUR-AMEND-PAID',
            '1.000000',
            '100.0000',
        );

        $invoice = PurchaseInvoice::query()->where('ulid', $purchaseUlid)->firstOrFail();
        $context = app(TenantContext::class);
        $cashType = AccountType::query()
            ->forTenant($context->tenantId())
            ->where('code', '0010')
            ->firstOrFail();

        $cashAccount = Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'PUR-AMEND-PAID-CASH',
            'name' => 'Purchase Amendment Paid Cash',
            'account_type_id' => $cashType->id,
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);

        PurchasePayment::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'purchase_invoice_id' => $invoice->id,
            'account_id' => $cashAccount->id,
            'method' => 'cash',
            'reference' => null,
            'amount' => '80.0000',
            'journal_entry_ulid' => null,
            'idempotency_key' => 'purchase-amend-paid-payment',
            'created_by' => $context->userId(),
        ]);

        $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '1.000000',
                'unit_cost' => '50.0000',
            ]],
            'revision_reason' => 'Would create supplier overpayment',
        ], $this->idem('purchase-amend-paid-r1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'PURCHASE_AMENDMENT_BELOW_PAID');

        $this->assertSame(0, DocumentRevision::query()->count());
        $this->assertSame('100.0000', (string) $this->balanceFor($productUlid)->stock_value);
    }

    public function test_purchase_amendment_blocks_changed_existing_product_after_downstream_stock_activity(): void
    {
        $this->signInOwner('purchase-amend-downstream')->assertOk();
        $this->configurePurchaseClearing();

        [$purchaseUlid, $productUlid, $unitUlid] = $this->createPostedPurchase(
            'PUR-AMEND-MOVED',
            '5.000000',
            '10.0000',
        );

        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $invoice = PurchaseInvoice::query()->where('ulid', $purchaseUlid)->firstOrFail();

        app(PostStockMovementAction::class)->execute([
            'warehouse' => $invoice->warehouse()->firstOrFail(),
            'product' => $product,
            'movement_type' => StockMovementType::Sale,
            'quantity' => '-1.000000',
            'unit_cost' => '10.0000',
            'reference_type' => 'test_sale',
            'reference_ulid' => null,
            'occurred_at' => now(),
            'idempotency_key' => 'purchase-amend-downstream-sale',
            'update_average_cost' => false,
        ]);

        $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '6.000000',
                'unit_cost' => '10.0000',
            ]],
            'revision_reason' => 'Unsafe retroactive stock change',
        ], $this->idem('purchase-amend-downstream-r1'))
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'PURCHASE_AMENDMENT_STOCK_ALREADY_MOVED');

        $this->assertSame(0, DocumentRevision::query()->count());
        $this->assertSame('4.000000', (string) $this->balanceFor($productUlid)->quantity);
    }

    public function test_purchase_amendment_reverses_effective_journal_and_posts_corrected_journal(): void
    {
        $this->signInOwner('purchase-amend-accounting')->assertOk();
        $this->configurePurchaseClearing();

        [$purchaseUlid, $productUlid, $unitUlid] = $this->createPostedPurchase(
            'PUR-AMEND-ACCOUNT',
            '1.000000',
            '100.0000',
        );

        $invoice = PurchaseInvoice::query()->where('ulid', $purchaseUlid)->firstOrFail();
        $originalJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE)
            ->where('document_id', $invoice->id)
            ->with('lines')
            ->firstOrFail();

        $this->patchJson('/api/purchases/'.$purchaseUlid.'/amend', [
            'lines' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $unitUlid,
                'quantity' => '2.000000',
                'unit_cost' => '100.0000',
            ]],
            'revision_reason' => 'Supplier delivered another unit',
        ], $this->idem('purchase-amend-accounting-r1'))
            ->assertOk()
            ->assertJsonPath('grand_total', '200.0000')
            ->assertJsonPath('revision_number', 1);

        $reversal = JournalEntry::query()
            ->where('reverses_journal_entry_id', $originalJournal->id)
            ->with('lines')
            ->firstOrFail();

        $amendment = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE_AMENDMENT)
            ->where('document_id', $invoice->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertBalanced($reversal);
        $this->assertBalanced($amendment);

        $this->assertSame(
            '200.0000',
            (string) $amendment->lines->firstWhere('description', 'Purchase clearing')?->debit,
        );
        $this->assertSame(
            '200.0000',
            (string) $amendment->lines->firstWhere('description', 'Supplier payable')?->credit,
        );
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createPostedPurchase(
        string $supplierCode,
        string $quantity,
        string $unitCost,
    ): array {
        $warehouseUlid = $this->sessionWarehouseUlid();
        $unitUlid = $this->unitUlid('PCS');

        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => $supplierCode,
            'name' => 'Supplier '.$supplierCode,
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'Product '.$supplierCode,
            'base_unit_ulid' => $unitUlid,
        ])->assertCreated()->json('ulid');

        $purchaseUlid = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplierUlid,
            'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$purchaseUlid.'/lines', [
            'product_ulid' => $productUlid,
            'unit_ulid' => $unitUlid,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$purchaseUlid.'/post')
            ->assertOk();

        return [$purchaseUlid, $productUlid, $unitUlid];
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
                'code' => 'PUR-AMEND-CLEAR',
            ],
            [
                'name' => 'Purchase Amendment Clearing',
                'account_type_id' => $inventoryType->id,
                'is_active' => true,
                'created_by' => $tenantContext->userId(),
            ],
        );

        BusinessSetting::query()
            ->forTenant($tenantContext->tenantId())
            ->update(['purchase_clearing_account_id' => $clearing->id]);
    }

    private function balanceFor(string $productUlid): StockBalance
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        return StockBalance::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function assertBalanced(JournalEntry $journal): void
    {
        $debit = $journal->lines->reduce(
            fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->debit, 4),
            '0.0000',
        );
        $credit = $journal->lines->reduce(
            fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->credit, 4),
            '0.0000',
        );

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
