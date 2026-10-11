<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
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

        $ranged = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28'
        )->assertOk();

        $ranged->assertJsonCount(1, 'rows')
            ->assertJsonPath('carry_forward', '100.0000')
            ->assertJsonPath('rows.0.debit', '25.0000')
            ->assertJsonPath('rows.0.book_number', 'BOOK-7')
            ->assertJsonPath('rows.0.line_description', 'Range debit')
            ->assertJsonPath('rows.0.entry_description', 'Range transaction')
            ->assertJsonPath('rows.0.balance', '125.0000')
            ->assertJsonPath('totals.debit', '25.0000')
            ->assertJsonPath('totals.closing_balance', '125.0000');

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

        $areaFiltered->assertJsonCount(1, 'rows')
            ->assertJsonPath('filter_options.area_name.0.value', 'RYK')
            ->assertJsonPath('filter_options.area_name.0.label', 'RYK');

        $noMatch = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&filter_by=entry_date&only_for=2026-02-11'
        )->assertOk();

        $noMatch->assertJsonCount(0, 'rows')
            ->assertJsonPath('totals.debit', '0.0000');

        $this->assertNoInternalIds($grouped->json());

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&document_types%5B0%5D=sales_invoice'
        )->assertStatus(422);

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&group_by=unsupported'
        )->assertStatus(422);

        $withoutOpening = $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-02-01&date_to=2026-02-28&include_opening_balance=0'
        )->assertOk();

        $withoutOpening->assertJsonPath('carry_forward', '0.0000')
            ->assertJsonPath('rows.0.balance', '25.0000')
            ->assertJsonPath('totals.closing_balance', '25.0000');

        $this->getJson(
            '/api/parties/'.$customerUlid.'/ledger?type=customer&date_from=2026-03-01&date_to=2026-02-01'
        )->assertStatus(422);
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
