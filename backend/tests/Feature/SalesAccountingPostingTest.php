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

class SalesAccountingPostingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_taxable_sale_posts_balanced_revenue_tax_cogs_and_inventory_journal_idempotently(): void
    {
        $this->signInOwner('sales-accounting-post')->assertOk();
        $accounts = $this->configureAccounts(true);

        $product = $this->createProduct('Accounting Tax Item', '100.0000', true, '10.00000000');
        $this->giveStock($product, '10.000000', '25.0000');

        $payload = [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ];

        $first = $this->postJson('/api/sales', $payload, $this->idem('sales-accounting-post'))
            ->assertCreated()
            ->assertJsonPath('subtotal', '200.0000')
            ->assertJsonPath('tax_amount', '20.0000')
            ->assertJsonPath('grand_total', '220.0000');

        $sale = Sale::query()->where('ulid', $first->json('ulid'))->firstOrFail();

        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame('posted', $journal->status->value);
        $this->assertSame('sale-accounting:'.$sale->ulid, $journal->idempotency_key);
        $this->assertCount(5, $journal->lines);

        $this->assertLine($journal->id, $accounts['clearing']->id, '220.0000', '0.0000');
        $this->assertLine($journal->id, $accounts['revenue']->id, '0.0000', '200.0000');
        $this->assertLine($journal->id, $accounts['tax']->id, '0.0000', '20.0000');
        $this->assertLine($journal->id, $accounts['cogs']->id, '50.0000', '0.0000');
        $this->assertLine($journal->id, $accounts['inventory']->id, '0.0000', '50.0000');

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($journal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame('270.0000', $debit);
        $this->assertSame('270.0000', $credit);
        $this->assertSame('8.000000', $this->stock($product));

        $second = $this->postJson('/api/sales', $payload, $this->idem('sales-accounting-post'))
            ->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
                ->where('document_id', $sale->id)
                ->count(),
        );
        $this->assertSame('8.000000', $this->stock($product));
    }

    public function test_invoice_discount_after_tax_reduces_revenue_and_keeps_tax_payable_balanced(): void
    {
        $this->signInOwner('sales-accounting-invoice-discount')->assertOk();
        $accounts = $this->configureAccounts(true);

        $product = $this->createProduct(
            'Accounting Invoice Discount Item',
            '100.0000',
            true,
            '10.00000000',
        );
        $this->giveStock($product, '10.000000', '25.0000');

        $response = $this->postJson('/api/sales', [
            'invoice_discount_amount' => '10.0000',
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sales-accounting-invoice-discount'))
            ->assertCreated()
            ->assertJsonPath('subtotal', '100.0000')
            ->assertJsonPath('tax_amount', '10.0000')
            ->assertJsonPath('invoice_discount_amount', '10.0000')
            ->assertJsonPath('grand_total', '100.0000');

        $sale = Sale::query()
            ->where('ulid', $response->json('ulid'))
            ->firstOrFail();

        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertLine(
            $journal->id,
            $accounts['clearing']->id,
            '100.0000',
            '0.0000',
        );
        $this->assertLine(
            $journal->id,
            $accounts['revenue']->id,
            '0.0000',
            '90.0000',
        );
        $this->assertLine(
            $journal->id,
            $accounts['tax']->id,
            '0.0000',
            '10.0000',
        );
        $this->assertLine(
            $journal->id,
            $accounts['cogs']->id,
            '25.0000',
            '0.0000',
        );
        $this->assertLine(
            $journal->id,
            $accounts['inventory']->id,
            '0.0000',
            '25.0000',
        );

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($journal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame('125.0000', $debit);
        $this->assertSame('125.0000', $credit);
    }

    public function test_partial_sales_accounting_configuration_rolls_back_sale_and_stock(): void
    {
        $this->signInOwner('sales-accounting-partial')->assertOk();

        $revenue = $this->leafAccount('PARTIAL SALES REVENUE', 'SAR-100');
        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update(['sales_revenue_account_id' => $revenue->id]);

        $product = $this->createProduct('Partial Config Item', '100.0000');
        $this->giveStock($product, '10.000000', '25.0000');

        $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sales-accounting-partial'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'SALES_CLEARING_ACCOUNT_REQUIRED');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', 'sale')->count(),
        );
        $this->assertSame('10.000000', $this->stock($product));
    }

    public function test_taxable_sale_requires_sales_tax_payable_account_and_rolls_back(): void
    {
        $this->signInOwner('sales-accounting-tax-required')->assertOk();
        $this->configureAccounts(false);

        $product = $this->createProduct('Tax Account Required Item', '100.0000', true, '5.00000000');
        $this->giveStock($product, '10.000000', '25.0000');

        $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sales-accounting-tax-required'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'SALES_TAX_PAYABLE_ACCOUNT_REQUIRED');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(
            0,
            JournalEntry::query()->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)->count(),
        );
        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', 'sale')->count(),
        );
        $this->assertSame('10.000000', $this->stock($product));
    }

    /**
     * @return array{clearing:Account,revenue:Account,tax:?Account,inventory:Account,cogs:Account}
     */
    private function configureAccounts(bool $withTax): array
    {
        $clearing = $this->leafAccount('SALES CLEARING', 'SAC-100');
        $revenue = $this->leafAccount('SALES REVENUE', 'SAR-200');
        $tax = $withTax ? $this->leafAccount('SALES TAX PAYABLE', 'SAT-300') : null;
        $inventory = $this->leafAccount('INVENTORY CONTROL', 'SAI-400');
        $cogs = $this->leafAccount('COST OF GOODS SOLD', 'SAC-500');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'sales_clearing_account_id' => $clearing->id,
                'sales_revenue_account_id' => $revenue->id,
                'sales_tax_payable_account_id' => $tax?->id,
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

        return Product::query()
            ->where('ulid', $response->json('ulid'))
            ->firstOrFail();
    }

    private function giveStock(
        Product $product,
        string $quantity,
        string $averageCost,
    ): void {
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

    private function assertLine(
        int $journalId,
        int $accountId,
        string $debit,
        string $credit,
    ): void {
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
