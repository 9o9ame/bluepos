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
        $assets = $this->postJson('/api/coa/main-heads', ['name' => 'ASSETS', 'sort_order' => 1])->assertCreated()->json('ulid');
        $liab = $this->postJson('/api/coa/main-heads', ['name' => 'LIABILITIES', 'sort_order' => 2])->assertCreated()->json('ulid');
        $eqMain = $this->postJson('/api/coa/main-heads', ['name' => 'EQUITY', 'sort_order' => 3])->assertCreated()->json('ulid');

        $current = $this->postJson('/api/coa/sub-heads', ['main_head_ulid' => $assets, 'name' => 'CURRENT'])->assertCreated()->json('ulid');
        $short = $this->postJson('/api/coa/sub-heads', ['main_head_ulid' => $liab, 'name' => 'SHORT'])->assertCreated()->json('ulid');
        $cap = $this->postJson('/api/coa/sub-heads', ['main_head_ulid' => $eqMain, 'name' => 'CAP'])->assertCreated()->json('ulid');

        return [
            'ar' => $this->postJson('/api/coa/account-types', [
                'sub_head_ulid' => $current,
                'code' => '0011',
                'name' => 'AR',
                'is_receivable' => true,
            ])->assertCreated()->json('ulid'),
            'cash' => $this->postJson('/api/coa/account-types', [
                'sub_head_ulid' => $current,
                'code' => '0010',
                'name' => 'CASH',
                'is_cash' => true,
            ])->assertCreated()->json('ulid'),
            'ap' => $this->postJson('/api/coa/account-types', [
                'sub_head_ulid' => $short,
                'code' => '0020',
                'name' => 'AP',
                'is_payable' => true,
            ])->assertCreated()->json('ulid'),
            'equity' => $this->postJson('/api/coa/account-types', [
                'sub_head_ulid' => $cap,
                'code' => '0030',
                'name' => 'OPENING EQUITY',
            ])->assertCreated()->json('ulid'),
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

        $ensure = $this->postJson('/api/parties/'.$vendorUlid.'/ensure-leaf-account?type=vendor')
            ->assertOk();
        $ensure->assertJsonPath('status', 'already_linked');
        $leafCount = Account::query()->where('supplier_id', '!=', null)->count();
        $this->assertSame(1, $leafCount);

        $draftOnly = JournalEntry::query()->where('status', 'draft')->count();
        $this->assertSame(0, $draftOnly);
        $this->assertTrue(JournalLine::query()->exists());
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
    }
}
