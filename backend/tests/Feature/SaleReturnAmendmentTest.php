<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleReturnAmendmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_sales_return_can_be_amended_repeatedly_without_mutating_original_rows(): void
    {
        $this->signInOwner('sale-return-amend-basic')->assertOk();

        [$saleUlid, $returnUlid, $saleItemUlid, $productUlid] = $this->postedReturn(
            'Return Amend Product',
            '4.000000',
            '1.000000',
        );

        $document = SaleReturn::query()->where('ulid', $returnUlid)->firstOrFail();
        $originalLine = SaleReturnLine::query()
            ->where('sale_return_id', $document->id)
            ->firstOrFail();

        $first = $this->patchJson('/api/sales-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Customer returned one more item',
            'reason' => 'Quantity correction',
            'lines' => [[
                'sale_item_ulid' => $saleItemUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sale-return-amend-r1'))
            ->assertOk()
            ->assertJsonPath('ulid', $returnUlid)
            ->assertJsonPath('grand_total', '200.0000')
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('is_amended', true)
            ->assertJsonPath('lines.0.quantity', '2.000000');

        $this->assertSame('100.0000', (string) $document->fresh()->grand_total);
        $this->assertSame('1.000000', (string) $originalLine->fresh()->quantity);
        $this->assertSame('8.000000', $this->stockFor($productUlid));

        $this->patchJson('/api/sales-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Retry',
            'lines' => [[
                'sale_item_ulid' => $saleItemUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sale-return-amend-r1'))
            ->assertOk()
            ->assertJsonPath('revision_number', 1);

        $this->assertSame(
            1,
            DocumentRevision::query()
                ->where('document_type', 'sale_return')
                ->where('document_ulid', $returnUlid)
                ->count(),
        );

        $this->patchJson('/api/sales-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Correct quantity down',
            'lines' => [[
                'sale_item_ulid' => $saleItemUlid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sale-return-amend-r2'))
            ->assertOk()
            ->assertJsonPath('grand_total', '100.0000')
            ->assertJsonPath('revision_number', 2);

        $this->assertSame('7.000000', $this->stockFor($productUlid));
        $this->assertSame(
            2,
            DocumentRevision::query()
                ->where('document_type', 'sale_return')
                ->where('document_ulid', $returnUlid)
                ->count(),
        );

        $this->getJson('/api/sales/'.$saleUlid)
            ->assertOk()
            ->assertJsonPath('returned_amount', '100.0000');

        $this->assertNoInternalIds($first->json());
    }

    public function test_sales_return_amendment_requires_explicit_permission(): void
    {
        $this->signInOwner('sale-return-amend-permission')->assertOk();

        [, $returnUlid, $saleItemUlid] = $this->postedReturn(
            'Protected Return Item',
            '2.000000',
            '1.000000',
        );

        $permission = Permission::query()
            ->where('key', 'sales.return.amend_posted')
            ->firstOrFail();

        $membership = app(TenantContext::class)->membership();
        foreach ($membership->roles()->get() as $role) {
            $role->permissions()->detach($permission->id);
        }
        app(PermissionService::class)->clearCache();

        $this->patchJson('/api/sales-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Should be denied',
            'lines' => [[
                'sale_item_ulid' => $saleItemUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sale-return-amend-denied'))
            ->assertForbidden();

        $this->assertSame(0, DocumentRevision::query()->count());
    }

    public function test_sales_return_amendment_cannot_reduce_total_below_refunded_amount(): void
    {
        $this->signInOwner('sale-return-amend-refund')->assertOk();
        $this->configureAccounting(true);

        [$saleUlid, $returnUlid, $saleItemUlid] = $this->postedReturn(
            'Refund Floor Return Item',
            '2.000000',
            '2.000000',
        );

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '200.0000',
            'method' => 'cash',
        ], $this->idem('sale-return-amend-payment'))->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '150.0000',
            'method' => 'cash',
        ], $this->idem('sale-return-amend-refund-payment'))->assertCreated();

        $this->patchJson('/api/sales-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Would undercut refund',
            'lines' => [[
                'sale_item_ulid' => $saleItemUlid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sale-return-amend-refund-r1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'SALE_RETURN_AMENDMENT_BELOW_REFUNDED');

        $this->assertSame(0, DocumentRevision::query()->count());
    }

    public function test_sales_return_amendment_reverses_previous_effective_journal_and_posts_new_one(): void
    {
        $this->signInOwner('sale-return-amend-accounting')->assertOk();
        $this->configureAccounting(false);

        [, $returnUlid, $saleItemUlid] = $this->postedReturn(
            'Accounting Return Item',
            '3.000000',
            '1.000000',
        );

        $document = SaleReturn::query()->where('ulid', $returnUlid)->firstOrFail();
        $originalJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $this->patchJson('/api/sales-returns/'.$returnUlid.'/amend', [
            'revision_reason' => 'Increase posted return',
            'lines' => [[
                'sale_item_ulid' => $saleItemUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sale-return-amend-accounting-r1'))
            ->assertOk()
            ->assertJsonPath('grand_total', '200.0000')
            ->assertJsonPath('revision_number', 1);

        $reversal = JournalEntry::query()
            ->where('reverses_journal_entry_id', $originalJournal->id)
            ->where('document_type', $originalJournal->document_type.'_reversal')
            ->with('lines')
            ->firstOrFail();

        $amendment = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN_AMENDMENT)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertBalanced($reversal);
        $this->assertBalanced($amendment);
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function postedReturn(
        string $productName,
        string $saleQty,
        string $returnQty,
    ): array {
        $productUlid = $this->createProduct($productName, '100.0000');
        $this->giveStock($productUlid, '10.000000');

        $sale = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $productUlid,
                'quantity' => $saleQty,
            ]],
        ], $this->idem('sale-'.md5($productName)))
            ->assertCreated();

        $saleUlid = (string) $sale->json('ulid');
        $saleItemUlid = (string) $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $return = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('return-'.md5($productName)))
            ->assertCreated();

        $returnUlid = (string) $return->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => $returnQty,
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk();

        return [$saleUlid, $returnUlid, $saleItemUlid, $productUlid];
    }

    private function configureAccounting(bool $cash): void
    {
        $context = app(TenantContext::class);
        $assetType = AccountType::query()
            ->forTenant($context->tenantId())
            ->where('code', '0060')
            ->firstOrFail();

        $accounts = [];
        foreach ([
            'sales' => 'SR-AMEND-SALES',
            'inventory' => 'SR-AMEND-INV',
            'cogs' => 'SR-AMEND-COGS',
            'clearing' => 'SR-AMEND-CLEAR',
        ] as $key => $code) {
            $accounts[$key] = Account::query()->create([
                'tenant_id' => $context->tenantId(),
                'code' => $code,
                'name' => strtoupper($key).' Return Amendment',
                'account_type_id' => $assetType->id,
                'is_active' => true,
                'created_by' => $context->userId(),
            ]);
        }

        $updates = [
            'sales_revenue_account_id' => $accounts['sales']->id,
            'inventory_account_id' => $accounts['inventory']->id,
            'cogs_account_id' => $accounts['cogs']->id,
            'sales_clearing_account_id' => $accounts['clearing']->id,
        ];

        if ($cash) {
            $cashType = AccountType::query()
                ->forTenant($context->tenantId())
                ->where('code', '0010')
                ->firstOrFail();

            $cashAccount = Account::query()->create([
                'tenant_id' => $context->tenantId(),
                'code' => 'SR-AMEND-CASH',
                'name' => 'Sales Return Amendment Cash',
                'account_type_id' => $cashType->id,
                'is_active' => true,
                'created_by' => $context->userId(),
            ]);
            $updates['default_cash_account_id'] = $cashAccount->id;
        }

        BusinessSetting::query()
            ->forTenant($context->tenantId())
            ->update($updates);
    }

    private function createProduct(string $name, string $retail): string
    {
        return $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retail,
            ]],
        ])->assertCreated()->json('ulid');
    }

    private function giveStock(string $productUlid, string $quantity): void
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'warehouse_id' => app(TenantContext::class)->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'branch_id' => app(TenantContext::class)->branchId(),
                'quantity' => '0.000000',
            ],
        );

        $balance->quantity = bcadd((string) $balance->quantity, $quantity, 6);
        $balance->average_cost = '10.0000';
        $balance->stock_value = bcmul((string) $balance->quantity, '10.0000', 4);
        $balance->save();
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
