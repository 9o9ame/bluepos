<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerReceivableSettlementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_sale_posts_to_ar_and_cash_then_on_account_settle_without_double_posting(): void
    {
        $this->signInOwner('customer-ar-settlement')->assertOk();
        $accounts = $this->configureAccounts();

        $customerUlid = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'AR-CUST-1',
            'name' => 'AR Customer',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated()->json('ulid');

        $customerLeaf = Account::query()
            ->whereNotNull('customer_id')
            ->where('code', 'AR-CUST-1')
            ->firstOrFail();

        $product = $this->createProduct('AR Settlement Item', '100.0000');
        $this->giveStock($product, '10.000000', '25.0000');

        $saleResponse = $this->postJson('/api/sales', [
            'customer_ulid' => $customerUlid,
            'payment_due' => true,
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('customer-ar-sale'))
            ->assertCreated()
            ->assertJsonPath('grand_total', '200.0000')
            ->assertJsonPath('balance_due', '200.0000');

        $sale = Sale::query()->where('ulid', $saleResponse->json('ulid'))->firstOrFail();

        $invoiceJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertLine($invoiceJournal->id, $customerLeaf->id, '200.0000', '0.0000');
        $this->assertLine($invoiceJournal->id, $accounts['revenue']->id, '0.0000', '200.0000');

        $cashPayment = $this->postJson('/api/sales/'.$sale->ulid.'/payments', [
            'amount' => '50.0000',
            'method' => 'cash',
        ], $this->idem('customer-ar-cash'))
            ->assertCreated()
            ->assertJsonPath('amount', '50.0000');

        $cashPaymentRow = SalePayment::query()
            ->where('ulid', $cashPayment->json('ulid'))
            ->firstOrFail();

        $cashJournal = JournalEntry::query()
            ->where('ulid', $cashPaymentRow->journal_entry_ulid)
            ->firstOrFail();

        $this->assertLine($cashJournal->id, $accounts['cash']->id, '50.0000', '0.0000');
        $this->assertLine($cashJournal->id, $customerLeaf->id, '0.0000', '50.0000');

        $onAccount = $this->postJson('/api/sales/'.$sale->ulid.'/payments', [
            'amount' => '150.0000',
            'method' => 'credit',
        ], $this->idem('customer-ar-credit'))
            ->assertCreated()
            ->assertJsonPath('amount', '150.0000')
            ->assertJsonPath('method', 'credit');

        $onAccountRow = SalePayment::query()
            ->where('ulid', $onAccount->json('ulid'))
            ->firstOrFail();

        $this->assertNull($onAccountRow->journal_entry_ulid);
        $this->assertSame($customerLeaf->id, (int) $onAccountRow->account_id);

        $debit = bcadd(
            (string) JournalLine::query()
                ->where('account_id', $customerLeaf->id)
                ->sum('debit'),
            '0',
            4,
        );
        $credit = bcadd(
            (string) JournalLine::query()
                ->where('account_id', $customerLeaf->id)
                ->sum('credit'),
            '0',
            4,
        );

        $this->assertSame('200.0000', $debit);
        $this->assertSame('50.0000', $credit);
        $this->assertSame('150.0000', bcsub($debit, $credit, 4));

        $this->getJson('/api/sales/'.$sale->ulid)
            ->assertOk()
            ->assertJsonPath('paid_amount', '200.0000')
            ->assertJsonPath('balance_due', '0.0000');
    }

    public function test_customer_return_reverses_ar_and_cash_refund_debits_ar(): void
    {
        $this->signInOwner('customer-ar-return')->assertOk();
        $accounts = $this->configureAccounts();

        $customerUlid = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'AR-CUST-2',
            'name' => 'AR Return Customer',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated()->json('ulid');

        $customerLeaf = Account::query()
            ->whereNotNull('customer_id')
            ->where('code', 'AR-CUST-2')
            ->firstOrFail();

        $product = $this->createProduct('AR Return Item', '100.0000');
        $this->giveStock($product, '10.000000', '25.0000');

        $saleUlid = $this->postJson('/api/sales', [
            'customer_ulid' => $customerUlid,
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('customer-ar-return-sale'))
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('customer-ar-return-cash'))
            ->assertCreated();

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('customer-ar-return-doc'))
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('grand_total', '100.0000');

        $returnJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->with('lines')
            ->firstOrFail();

        $this->assertLine($returnJournal->id, $customerLeaf->id, '0.0000', '100.0000');

        $refund = $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('customer-ar-return-refund'))
            ->assertCreated();

        $refundJournal = JournalEntry::query()
            ->where('ulid', $refund->json('journal_entry_ulid'))
            ->firstOrFail();

        $this->assertLine($refundJournal->id, $customerLeaf->id, '100.0000', '0.0000');
        $this->assertLine($refundJournal->id, $accounts['cash']->id, '0.0000', '100.0000');
    }

    /**
     * @return array{cash:Account,revenue:Account,inventory:Account,cogs:Account}
     */
    private function configureAccounts(): array
    {
        $cash = $this->leafAccount('AR TEST CASH', 'AR-100');
        $revenue = $this->leafAccount('AR TEST REVENUE', 'AR-200');
        $inventory = $this->leafAccount('AR TEST INVENTORY', 'AR-300');
        $cogs = $this->leafAccount('AR TEST COGS', 'AR-400');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'default_cash_account_id' => $cash->id,
                'sales_revenue_account_id' => $revenue->id,
                'inventory_account_id' => $inventory->id,
                'cogs_account_id' => $cogs->id,
            ]);

        return compact('cash', 'revenue', 'inventory', 'cogs');
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

    private function createProduct(string $name, string $price): Product
    {
        $response = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $price,
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
