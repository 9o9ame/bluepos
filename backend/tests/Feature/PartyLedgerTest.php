<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PartyProfile;
use App\Models\PartyProfileType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartyLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{ap: string, ar: string, cash: string, equity: string}
     */
    private function seedCoa(): array
    {
        return [
            'ar' => $this->referenceAccountTypeUlid('0011'),
            'cash' => $this->referenceAccountTypeUlid('0010'),
            'ap' => $this->referenceAccountTypeUlid('0020'),
            'equity' => $this->referenceAccountTypeUlid('0050'),
        ];
    }

    private function configureEquity(string $equityTypeUlid): void
    {
        $equity = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'OBEQ',
            'name' => 'Opening Equity',
            'account_type_ulid' => $equityTypeUlid,
        ])->assertCreated();

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'opening_balance_equity_account_id' => Account::query()->where('ulid', $equity->json('ulid'))->value('id'),
            ]);
    }

    public function test_ledger_posted_only_running_balance_totals_and_ensure_leaf(): void
    {
        $this->signInOwner('ldg-main')->assertOk();
        $coa = $this->seedCoa();
        $this->configureEquity($coa['equity']);

        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'V1',
            'name' => 'Vendor One',
            'address' => 'Karachi',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated();
        $vendorUlid = $vendor->json('ulid');

        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'C1',
            'name' => 'Customer One',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated()->json('ulid');

        $manual = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'CASH1',
            'name' => 'Cash',
            'account_type_ulid' => $coa['cash'],
        ])->assertCreated()->json('ulid');

        $empty = $this->getJson('/api/parties/'.$vendorUlid.'/ledger?type=vendor')->assertOk();
        $empty->assertJsonPath('rows', []);
        $empty->assertJsonPath('totals.debit', '0.0000');
        $empty->assertJsonPath('totals.closing_balance', '0.0000');
        $this->assertNoInternalIds($empty->json());

        $opening = $this->postJson('/api/parties/'.$vendorUlid.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'Vendor OB',
            'debit' => '0.0000',
            'credit' => '1000.0000',
        ])->assertCreated()->json('ulid');

        $this->getJson('/api/parties/'.$vendorUlid.'/ledger?type=vendor')
            ->assertOk()
            ->assertJsonPath('rows', []);

        $this->postJson('/api/parties/'.$vendorUlid.'/opening-balances/'.$opening.'/post?type=vendor')
            ->assertOk();

        $ledger = $this->getJson('/api/parties/'.$vendorUlid.'/ledger?type=vendor')->assertOk();
        $this->assertCount(1, $ledger->json('rows'));
        $ledger->assertJsonPath('rows.0.doc', 'Opening Balance');
        $ledger->assertJsonPath('rows.0.credit', '1000.0000');
        $ledger->assertJsonPath('rows.0.balance', '1000.0000');
        $ledger->assertJsonPath('totals.credit', '1000.0000');
        $ledger->assertJsonPath('totals.closing_balance', '1000.0000');
        $ledger->assertJsonPath('account.address', 'Karachi');

        $custOpen = $this->postJson('/api/parties/'.$customer.'/opening-balances?type=customer', [
            'opening_date' => '2026-01-01',
            'narration' => 'AR OB',
            'debit' => '250.0000',
            'credit' => '0.0000',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/parties/'.$customer.'/opening-balances/'.$custOpen.'/post?type=customer')->assertOk();

        $custLedger = $this->getJson('/api/parties/'.$customer.'/ledger?type=customer')->assertOk();
        $custLedger->assertJsonPath('rows.0.debit', '250.0000');
        $custLedger->assertJsonPath('rows.0.balance', '250.0000');

        $manOpen = $this->postJson('/api/parties/'.$manual.'/opening-balances?type=account', [
            'opening_date' => '2026-01-02',
            'narration' => 'Cash OB',
            'debit' => '50.0000',
            'credit' => '0.0000',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/parties/'.$manual.'/opening-balances/'.$manOpen.'/post?type=account')->assertOk();
        $this->getJson('/api/parties/'.$manual.'/ledger?type=account')
            ->assertOk()
            ->assertJsonPath('rows.0.balance', '50.0000');

        $balances = $this->postJson('/api/parties/ledger-balances', [
            'items' => [
                ['type' => 'vendor', 'ulid' => $vendorUlid],
                ['type' => 'customer', 'ulid' => $customer],
                ['type' => 'account', 'ulid' => $manual],
            ],
        ])->assertOk();

        $balances
            ->assertJsonPath('balances.vendor:'.$vendorUlid, '1000.0000')
            ->assertJsonPath('balances.customer:'.$customer, '250.0000')
            ->assertJsonPath('balances.account:'.$manual, '50.0000');
        $this->assertNoInternalIds($balances->json());

        $ensure = $this->postJson('/api/parties/'.$vendorUlid.'/ensure-leaf-account?type=vendor')
            ->assertOk();
        $ensure->assertJsonPath('status', 'already_linked');
        $leafCount = Account::query()->where('supplier_id', '!=', null)->count();
        $this->assertSame(1, $leafCount);

        $draftOnly = JournalEntry::query()->where('status', 'draft')->count();
        $this->assertSame(0, $draftOnly);
        $this->assertTrue(JournalLine::query()->exists());
    }

    public function test_ledger_supports_date_range_carry_forward_and_opening_balance_exclusion(): void
    {
        $this->signInOwner('ldg-range')->assertOk();
        $coa = $this->seedCoa();
        $this->configureEquity($coa['equity']);

        $customerUlid = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'C-RANGE',
            'name' => 'Customer Range',
            'area' => 'RYK',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated()->json('ulid');

        $opening = $this->postJson('/api/parties/'.$customerUlid.'/opening-balances?type=customer', [
            'opening_date' => '2026-01-01',
            'narration' => 'Opening',
            'debit' => '100.0000',
            'credit' => '0.0000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/parties/'.$customerUlid.'/opening-balances/'.$opening.'/post?type=customer')
            ->assertOk();

        $leaf = Account::query()->where('customer_id', '!=', null)->firstOrFail();
        $entry = JournalEntry::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'branch_id' => app(TenantContext::class)->branchId(),
            'document_type' => 'journal_voucher',
            'voucher_number' => 'JV-RANGE-1',
            'book_number' => 'BOOK-7',
            'entry_date' => '2026-02-10',
            'description' => 'Range transaction',
            'status' => 'posted',
            'created_by' => app(TenantContext::class)->userId(),
            'posted_by' => app(TenantContext::class)->userId(),
            'posted_at' => now(),
        ]);

        JournalLine::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'journal_entry_id' => $entry->id,
            'account_id' => $leaf->id,
            'description' => 'Range debit',
            'debit' => '25.0000',
            'credit' => '0.0000',
            'sort_order' => 1,
        ]);

        $salesman = PartyProfile::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'code' => 'SM-RANGE',
            'name' => 'Sales Person Range',
            'is_active' => true,
            'created_by' => app(TenantContext::class)->userId(),
        ]);

        $warehouse = Warehouse::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->firstOrFail();

        $sale = Sale::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'branch_id' => app(TenantContext::class)->branchId(),
            'warehouse_id' => $warehouse->id,
            'customer_id' => $leaf->customer_id,
            'salesman_party_profile_id' => $salesman->id,
            'document_number' => 'SAL-LDG-RANGE',
            'status' => 'posted',
            'sale_date' => '2026-02-12',
            'price_type' => 'wholesale',
            'subtotal' => '15.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '15.0000',
            'idempotency_key' => 'sale-ledger-range',
            'created_by' => app(TenantContext::class)->userId(),
            'posted_at' => now(),
        ]);

        $saleEntry = JournalEntry::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'branch_id' => app(TenantContext::class)->branchId(),
            'document_type' => 'sale_payment',
            'document_id' => $sale->id,
            'voucher_number' => 'SP-RANGE-1',
            'entry_date' => '2026-02-12',
            'description' => 'Sale-linked payment',
            'status' => 'posted',
            'created_by' => app(TenantContext::class)->userId(),
            'posted_by' => app(TenantContext::class)->userId(),
            'posted_at' => now(),
        ]);

        JournalLine::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'journal_entry_id' => $saleEntry->id,
            'account_id' => $leaf->id,
            'description' => 'Sale-linked debit',
            'debit' => '15.0000',
            'credit' => '0.0000',
            'sort_order' => 1,
        ]);

        $ranged = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28'
        )->assertOk();

        $ranged->assertJsonCount(2, 'rows')
            ->assertJsonPath('carry_forward', '100.0000')
            ->assertJsonPath('rows.0.debit', '25.0000')
            ->assertJsonPath('rows.0.book_number', 'BOOK-7')
            ->assertJsonPath('rows.0.line_description', 'Range debit')
            ->assertJsonPath('rows.0.entry_description', 'Range transaction')
            ->assertJsonPath('rows.0.balance', '125.0000')
            ->assertJsonPath('rows.1.doc', 'SALE PAYMENT')
            ->assertJsonPath('rows.1.debit', '15.0000')
            ->assertJsonPath('rows.1.balance', '140.0000')
            ->assertJsonPath('totals.debit', '40.0000')
            ->assertJsonPath('totals.closing_balance', '140.0000');

        $filtered = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&document_types%5B0%5D=journal_voucher'
        )->assertOk();

        $filtered->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.doc', 'JOURNAL VOUCHER')
            ->assertJsonPath('rows.0.book_number', 'BOOK-7');

        $grouped = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&group_by=document'
        )->assertOk();

        $grouped->assertJsonPath('account.area', 'RYK')
            ->assertJsonPath('rows.0.group_key', 'journal_voucher')
            ->assertJsonPath('rows.0.group_label', 'JOURNAL VOUCHER')
            ->assertJsonPath('rows.0.subgroup_balance', '25.0000')
            ->assertJsonPath('group_totals.0.key', 'journal_voucher')
            ->assertJsonPath('group_totals.0.label', 'JOURNAL VOUCHER')
            ->assertJsonPath('group_totals.0.debit', '25.0000')
            ->assertJsonPath('group_totals.0.credit', '0.0000');

        $areaFiltered = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&filter_by=area_name&only_for=RYK'
        )->assertOk();

        $areaFiltered->assertJsonCount(2, 'rows')
            ->assertJsonPath('filter_options.area_name.0.value', 'RYK')
            ->assertJsonPath('filter_options.area_name.0.label', 'RYK');

        $salesPersonGrouped = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&group_by=sales_person&sale_mode=wholesale'
        )->assertOk();

        $salesPersonGrouped->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.salesman_name', 'Sales Person Range')
            ->assertJsonPath('rows.0.sale_price_type', 'wholesale')
            ->assertJsonPath('rows.0.group_key', $salesman->ulid)
            ->assertJsonPath('rows.0.group_label', 'Sales Person Range')
            ->assertJsonPath('filter_options.sales_person.0.value', $salesman->ulid)
            ->assertJsonPath('filter_options.sales_person.0.label', 'Sales Person Range');
        $this->assertNoInternalIds($salesPersonGrouped->json());

        $nonWholesale = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&sale_mode=non_wholesale'
        )->assertOk();

        $nonWholesale->assertJsonCount(0, 'rows');

        $noMatch = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&filter_by=entry_date&only_for=2026-02-11'
        )->assertOk();

        $noMatch->assertJsonCount(0, 'rows')
            ->assertJsonPath('totals.debit', '0.0000');

        $this->assertNoInternalIds($grouped->json());

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&document_types%5B0%5D=unsupported_sales_document'
        )->assertStatus(422);

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&group_by=unsupported'
        )->assertStatus(422);

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&sale_mode=unsupported'
        )->assertStatus(422);

        $withoutOpening = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&include_opening_balance=0'
        )->assertOk();

        $withoutOpening->assertJsonCount(2, 'rows')
            ->assertJsonPath('carry_forward', '0.0000')
            ->assertJsonPath('rows.0.balance', '25.0000')
            ->assertJsonPath('rows.1.balance', '40.0000')
            ->assertJsonPath('totals.debit', '40.0000')
            ->assertJsonPath('totals.closing_balance', '40.0000');

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-03-01&date_to=2026-02-01'
        )->assertStatus(422);
    }

    public function test_customer_ledger_exposes_posted_sales_invoice_and_return_with_sales_metadata(): void
    {
        $this->signInOwner('ldg-sales-accounting')->assertOk();

        $context = app(TenantContext::class);
        $coa = $this->seedCoa();

        $customerUlid = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'C-SALES-LEDGER',
            'name' => 'Sales Ledger Customer',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated()->json('ulid');

        $type = \App\Models\AccountType::query()
            ->forTenant($context->tenantId())
            ->orderBy('id')
            ->firstOrFail();

        $revenue = Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'LDG-SALES-REV',
            'name' => 'Ledger Sales Revenue',
            'account_type_id' => $type->id,
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);

        $inventory = Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'LDG-SALES-INV',
            'name' => 'Ledger Inventory',
            'account_type_id' => $type->id,
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);

        $cogs = Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'LDG-SALES-COGS',
            'name' => 'Ledger COGS',
            'account_type_id' => $type->id,
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);

        BusinessSetting::query()
            ->forTenant($context->tenantId())
            ->update([
                'sales_revenue_account_id' => $revenue->id,
                'inventory_account_id' => $inventory->id,
                'cogs_account_id' => $cogs->id,
            ]);

        $salesman = PartyProfile::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'SM-LDG-SALES',
            'name' => 'Ledger Sales Person',
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);
        PartyProfileType::query()->create([
            'party_profile_id' => $salesman->id,
            'type' => 'salesman',
        ]);

        $productResponse = $this->postJson('/api/products', [
            'name' => 'Ledger Wholesale Item',
            'base_unit_ulid' => $this->unitUlidForLedger('PCS'),
            'prices' => [
                ['price_type' => 'retail', 'amount' => '120.0000'],
                ['price_type' => 'wholesale', 'amount' => '100.0000'],
            ],
        ])->assertCreated();

        $product = Product::query()
            ->where('ulid', $productResponse->json('ulid'))
            ->firstOrFail();

        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $context->tenantId(),
                'warehouse_id' => $context->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'branch_id' => $context->branchId(),
                'quantity' => '0.000000',
            ],
        );
        $balance->quantity = '10.000000';
        $balance->average_cost = '25.0000';
        $balance->stock_value = '250.0000';
        $balance->save();

        $saleUlid = $this->postJson('/api/sales', [
            'customer_ulid' => $customerUlid,
            'salesman_ulid' => $salesman->ulid,
            'price_type' => 'wholesale',
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2.000000',
            ]],
        ], ['Idempotency-Key' => 'ldg-sales-invoice'])
            ->assertCreated()
            ->assertJsonPath('grand_total', '200.0000')
            ->json('ulid');

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], ['Idempotency-Key' => 'ldg-sales-return'])
            ->assertCreated()
            ->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('grand_total', '100.0000');

        $ledger = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&sale_mode=wholesale&group_by=sales_person'
        )->assertOk();

        $ledger->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.doc', 'SALE INVOICE')
            ->assertJsonPath('rows.0.debit', '200.0000')
            ->assertJsonPath('rows.0.salesman_name', 'Ledger Sales Person')
            ->assertJsonPath('rows.0.sale_price_type', 'wholesale')
            ->assertJsonPath('rows.0.group_key', $salesman->ulid)
            ->assertJsonPath('rows.1.doc', 'SALES RETURN')
            ->assertJsonPath('rows.1.credit', '100.0000')
            ->assertJsonPath('rows.1.salesman_name', 'Ledger Sales Person')
            ->assertJsonPath('rows.1.sale_price_type', 'wholesale')
            ->assertJsonPath('rows.1.balance', '100.0000')
            ->assertJsonPath('totals.debit', '200.0000')
            ->assertJsonPath('totals.credit', '100.0000')
            ->assertJsonPath('totals.closing_balance', '100.0000');

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&document_types%5B0%5D=sale_invoice'
        )->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.doc', 'SALE INVOICE');

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&document_types%5B0%5D=sale_return'
        )->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.doc', 'SALES RETURN');

        $this->assertNoInternalIds($ledger->json());
    }

    private function unitUlidForLedger(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }

    public function test_ledger_tenant_isolation(): void
    {
        $this->signInOwner('ldg-a')->assertOk();
        $coa = $this->seedCoa();
        $this->configureEquity($coa['equity']);
        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'VA',
            'name' => 'Vendor A',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated()->json('ulid');
        $opening = $this->postJson('/api/parties/'.$vendor.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'OB',
            'debit' => '0',
            'credit' => '10',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/parties/'.$vendor.'/opening-balances/'.$opening.'/post?type=vendor')->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('ldg-b')->assertOk();

        $this->getJson('/api/parties/'.$vendor.'/ledger?type=vendor')->assertNotFound();
        $this->postJson('/api/parties/'.$vendor.'/ensure-leaf-account?type=vendor')->assertNotFound();

        $this->postJson('/api/parties/ledger-balances', [
            'items' => [
                ['type' => 'vendor', 'ulid' => $vendor],
            ],
        ])->assertOk()
            ->assertJsonPath('balances.vendor:'.$vendor, '0.0000');
    }
}
