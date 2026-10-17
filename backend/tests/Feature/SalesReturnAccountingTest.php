<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesReturnAccountingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_taxable_sales_return_reverses_revenue_tax_and_original_cost_idempotently(): void
    {
        $this->signInOwner('sales-return-accounting')->assertOk();
        $accounts = $this->configureAccounts();

        $product = $this->createProduct('Return Accounting Item', '100.0000', true, '10.00000000');
        $this->giveStock($product, '10.000000', '25.0000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sales-return-accounting-sale'))
            ->assertCreated()
            ->assertJsonPath('grand_total', '220.0000')
            ->json('ulid');

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sales-return-accounting-return'))
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated()
            ->assertJsonPath('gross_amount', '100.0000')
            ->assertJsonPath('tax_amount', '10.0000')
            ->assertJsonPath('line_total', '110.0000');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('grand_total', '110.0000');

        $sale = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        $saleJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->firstOrFail();

        $returnJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->where('idempotency_key', 'sale-return-accounting:'.$returnUlid)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame($saleJournal->id, (int) $returnJournal->reverses_journal_entry_id);
        $this->assertLine($returnJournal->id, $accounts['revenue']->id, '100.0000', '0.0000');
        $this->assertLine($returnJournal->id, $accounts['tax']->id, '10.0000', '0.0000');
        $this->assertLine($returnJournal->id, $accounts['clearing']->id, '0.0000', '110.0000');
        $this->assertLine($returnJournal->id, $accounts['inventory']->id, '25.0000', '0.0000');
        $this->assertLine($returnJournal->id, $accounts['cogs']->id, '0.0000', '25.0000');

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($returnJournal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame('135.0000', $debit);
        $this->assertSame('135.0000', $credit);
        $this->assertSame('9.000000', $this->stock($product));

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')->assertOk();

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
                ->where('idempotency_key', 'sale-return-accounting:'.$returnUlid)
                ->count(),
        );
        $this->assertSame('9.000000', $this->stock($product));
    }

    public function test_return_from_invoice_discount_sale_reverses_proportional_discount(): void
    {
        $this->signInOwner('sales-return-invoice-discount')->assertOk();
        $accounts = $this->configureAccounts();

        $product = $this->createProduct(
            'Return Invoice Discount Item',
            '100.0000',
            true,
            '10.00000000',
        );
        $this->giveStock($product, '10.000000', '25.0000');

        $saleUlid = $this->postJson('/api/sales', [
            'invoice_discount_amount' => '20.0000',
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sales-return-invoice-discount-sale'))
            ->assertCreated()
            ->assertJsonPath('subtotal', '200.0000')
            ->assertJsonPath('tax_amount', '20.0000')
            ->assertJsonPath('invoice_discount_amount', '20.0000')
            ->assertJsonPath('grand_total', '200.0000')
            ->json('ulid');

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sales-return-invoice-discount-return'))
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated()
            ->assertJsonPath('gross_amount', '100.0000')
            ->assertJsonPath('tax_amount', '10.0000')
            ->assertJsonPath('line_total', '110.0000');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('discount_amount', '10.0000')
            ->assertJsonPath('tax_amount', '10.0000')
            ->assertJsonPath('grand_total', '100.0000');

        $returnJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->where('idempotency_key', 'sale-return-accounting:'.$returnUlid)
            ->with('lines')
            ->firstOrFail();

        $this->assertLine(
            $returnJournal->id,
            $accounts['revenue']->id,
            '90.0000',
            '0.0000',
        );
        $this->assertLine(
            $returnJournal->id,
            $accounts['tax']->id,
            '10.0000',
            '0.0000',
        );
        $this->assertLine(
            $returnJournal->id,
            $accounts['clearing']->id,
            '0.0000',
            '100.0000',
        );
        $this->assertLine(
            $returnJournal->id,
            $accounts['inventory']->id,
            '25.0000',
            '0.0000',
        );
        $this->assertLine(
            $returnJournal->id,
            $accounts['cogs']->id,
            '0.0000',
            '25.0000',
        );
    }

    public function test_return_accounting_requires_original_sale_journal_and_rolls_back_stock(): void
    {
        $this->signInOwner('sales-return-missing-sale-journal')->assertOk();

        $product = $this->createProduct('Legacy Return Item', '100.0000');
        $this->giveStock($product, '10.000000', '25.0000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('legacy-return-sale'))
            ->assertCreated()
            ->json('ulid');

        $sale = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->delete();

        $this->configureAccounts();

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('legacy-return-document'))
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'SALE_ACCOUNTING_REQUIRED');

        $this->assertSame('8.000000', $this->stock($product));
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('movement_type', 'sale_return')
                ->where('reference_ulid', $returnUlid)
                ->count(),
        );
        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
                ->count(),
        );
    }

    public function test_general_sales_return_uses_cashier_price_discount_current_tax_and_average_cost(): void
    {
        $this->signInOwner('general-sales-return')->assertOk();
        $accounts = $this->configureAccounts();
        $cashInHand = $this->leafAccount('CASH IN HAND', 'SRA-CASH');

        $product = $this->createProduct(
            'General Return Item',
            '125.0000',
            true,
            '10.00000000',
        );
        $this->giveStock($product, '10.000000', '25.0000');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'account_ulid' => $cashInHand->ulid,
            'return_date' => now()->toDateString(),
        ], $this->idem('general-sales-return-document'))
            ->assertCreated()
            ->assertJsonPath('return_mode', 'general')
            ->assertJsonPath('original_sale', null)
            ->assertJsonPath('settlement_account.ulid', $cashInHand->ulid)
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'product_ulid' => $product->ulid,
            'quantity' => '2.000000',
            'unit_price' => '100.0000',
            'discount_percent' => '10.00000000',
        ])->assertCreated()
            ->assertJsonPath('gross_amount', '200.0000')
            ->assertJsonPath('discount_percent', '10.00000000')
            ->assertJsonPath('discount_amount', '20.0000')
            ->assertJsonPath('tax_percent', '10.00000000')
            ->assertJsonPath('tax_amount', '18.0000')
            ->assertJsonPath('line_total', '198.0000');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('subtotal', '200.0000')
            ->assertJsonPath('discount_amount', '20.0000')
            ->assertJsonPath('tax_amount', '18.0000')
            ->assertJsonPath('grand_total', '198.0000');

        $return = AppModelsSaleReturn::query()->where('ulid', $returnUlid)->firstOrFail();
        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->where('document_id', $return->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertNull($journal->reverses_journal_entry_id);
        $this->assertLine($journal->id, $accounts['revenue']->id, '180.0000', '0.0000');
        $this->assertLine($journal->id, $accounts['tax']->id, '18.0000', '0.0000');
        $this->assertLine($journal->id, $cashInHand->id, '0.0000', '198.0000');
        $this->assertLine($journal->id, $accounts['inventory']->id, '50.0000', '0.0000');
        $this->assertLine($journal->id, $accounts['cogs']->id, '0.0000', '50.0000');

        $movement = StockMovement::query()
            ->where('movement_type', 'sale_return')
            ->where('reference_ulid', $returnUlid)
            ->firstOrFail();

        $this->assertSame('25.0000', (string) $movement->unit_cost);
        $this->assertSame('12.000000', $this->stock($product));
    }

    public function test_general_sales_return_requires_current_average_cost_on_post(): void
    {
        $this->signInOwner('general-sales-return-no-cost')->assertOk();
        $this->configureAccounts();
        $cashInHand = $this->leafAccount('CASH IN HAND NO COST', 'SRA-CASH-2');

        $product = $this->createProduct('General Return No Cost', '100.0000');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'account_ulid' => $cashInHand->ulid,
        ], $this->idem('general-sales-return-no-cost-document'))
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'product_ulid' => $product->ulid,
            'quantity' => '1.000000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'GENERAL_RETURN_COST_REQUIRED');

        $this->assertSame(
            0,
            StockMovement::query()
                ->where('movement_type', 'sale_return')
                ->where('reference_ulid', $returnUlid)
                ->count(),
        );
    }

    /**
     * @return array{clearing:Account,revenue:Account,tax:Account,inventory:Account,cogs:Account}
     */
    private function configureAccounts(): array
    {
        $clearing = $this->leafAccount('RETURN CLEARING', 'SRA-100');
        $revenue = $this->leafAccount('RETURN REVENUE', 'SRA-200');
        $tax = $this->leafAccount('RETURN TAX PAYABLE', 'SRA-300');
        $inventory = $this->leafAccount('RETURN INVENTORY', 'SRA-400');
        $cogs = $this->leafAccount('RETURN COGS', 'SRA-500');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'sales_clearing_account_id' => $clearing->id,
                'sales_revenue_account_id' => $revenue->id,
                'sales_tax_payable_account_id' => $tax->id,
                'inventory_account_id' => $inventory->id,
                'cogs_account_id' => $cogs->id,
            ]);

        return compact('clearing', 'revenue', 'tax', 'inventory', 'cogs');
    }

    private function leafAccount(string $name, string $code): Account
    {
        $context = app(TenantContext::class);
        $type = AccountType::query()
            ->forTenant($context->tenantId())
            ->orderBy('id')
            ->firstOrFail();

        return Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => $code,
            'name' => $name,
            'account_type_id' => $type->id,
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);
    }

    private function createProduct(
        string $name,
        string $retailPrice,
        bool $taxable = false,
        string $taxPercent = '0.00000000',
    ): Product {
        $response = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'is_taxable' => $taxable,
            'tax_percent' => $taxPercent,
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retailPrice,
            ]],
        ])->assertCreated();

        return Product::query()->where('ulid', $response->json('ulid'))->firstOrFail();
    }

    private function giveStock(Product $product, string $quantity, string $averageCost): void
    {
        $context = app(TenantContext::class);

        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'warehouse_id' => $context->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'branch_id' => $context->branchId(),
                'quantity' => '0.000000',
            ],
        );

        $balance->quantity = bcadd((string) $balance->quantity, $quantity, 6);
        $balance->average_cost = $averageCost;
        $balance->stock_value = bcmul((string) $balance->quantity, $averageCost, 4);
        $balance->save();
    }

    private function stock(Product $product): string
    {
        return (string) StockBalance::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    private function assertLine(int $journalId, int $accountId, string $debit, string $credit): void
    {
        $line = JournalLine::query()
            ->where('journal_entry_id', $journalId)
            ->where('account_id', $accountId)
            ->firstOrFail();

        $this->assertSame($debit, (string) $line->debit);
        $this->assertSame($credit, (string) $line->credit);
    }

    /** @return array<string,string> */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
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
