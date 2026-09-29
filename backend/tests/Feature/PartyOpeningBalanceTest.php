<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartyOpeningBalanceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{ap: string, ar: string, cash: string, equity: string}
     */
    private function seedCoa(): array
    {
        $assets = $this->postJson('/api/coa/main-heads', [
            'name' => 'ASSETS',
            'sort_order' => 1,
        ])->assertCreated()->json('ulid');
        $liab = $this->postJson('/api/coa/main-heads', [
            'name' => 'LIABILITIES',
            'sort_order' => 2,
        ])->assertCreated()->json('ulid');
        $equityMain = $this->postJson('/api/coa/main-heads', [
            'name' => 'EQUITY',
            'sort_order' => 3,
        ])->assertCreated()->json('ulid');

        $current = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $assets,
            'name' => 'CURRENT ASSETS',
        ])->assertCreated()->json('ulid');
        $short = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $liab,
            'name' => 'SHORT TERM',
        ])->assertCreated()->json('ulid');
        $eqSub = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $equityMain,
            'name' => 'CAPITAL',
        ])->assertCreated()->json('ulid');

        $ar = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $current,
            'code' => '0011',
            'name' => 'ACCOUNT RECEIVABLE',
            'is_receivable' => true,
        ])->assertCreated()->json('ulid');
        $cash = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $current,
            'code' => '0010',
            'name' => 'CASH',
            'is_cash' => true,
        ])->assertCreated()->json('ulid');
        $ap = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $short,
            'code' => '0020',
            'name' => 'ACCOUNT PAYABLE',
            'is_payable' => true,
        ])->assertCreated()->json('ulid');
        $equity = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $eqSub,
            'code' => '0030',
            'name' => 'OPENING BALANCE EQUITY',
        ])->assertCreated()->json('ulid');

        return compact('ap', 'ar', 'cash', 'equity');
    }

    private function configureEquityOffset(string $equityTypeUlid): Account
    {
        $equityAccount = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'OBEQ',
            'name' => 'Opening Balance Equity',
            'account_type_ulid' => $equityTypeUlid,
        ])->assertCreated();

        $account = Account::query()->where('ulid', $equityAccount->json('ulid'))->firstOrFail();
        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'opening_balance_equity_account_id' => $account->id,
            ]);

        return $account;
    }

    public function test_opening_draft_crud_validation_post_and_immutability(): void
    {
        $this->signInOwner('ob-acct')->assertOk();
        $coa = $this->seedCoa();
        $this->configureEquityOffset($coa['equity']);

        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'V-OB',
            'name' => 'Vendor Opening',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated();
        $vendorUlid = $vendor->json('ulid');

        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'C-OB',
            'name' => 'Customer Opening',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated();
        $customerUlid = $customer->json('ulid');

        $manual = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'CASH-OB',
            'name' => 'Cash Opening',
            'account_type_ulid' => $coa['cash'],
        ])->assertCreated();
        $manualUlid = $manual->json('ulid');

        $draft = $this->postJson('/api/parties/'.$vendorUlid.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'Vendor opening',
            'debit' => '0.0000',
            'credit' => '1500.5000',
        ])->assertCreated();
        $draft->assertJsonPath('status', 'draft');
        $draft->assertJsonPath('credit', '1500.5000');
        $this->assertNoInternalIds($draft->json());
        $openingUlid = $draft->json('ulid');

        $this->postJson('/api/parties/'.$vendorUlid.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'Bad both sides',
            'debit' => '10.0000',
            'credit' => '10.0000',
        ])->assertStatus(422);

        $this->postJson('/api/parties/'.$vendorUlid.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'Zero',
            'debit' => '0',
            'credit' => '0',
        ])->assertStatus(422);

        $this->patchJson('/api/parties/'.$vendorUlid.'/opening-balances/'.$openingUlid.'?type=vendor', [
            'narration' => 'Updated vendor opening',
            'credit' => '2000.0000',
            'debit' => '0.0000',
        ])->assertOk()->assertJsonPath('credit', '2000.0000');

        $this->postJson('/api/parties/'.$customerUlid.'/opening-balances?type=customer', [
            'opening_date' => '2026-01-01',
            'narration' => 'AR opening',
            'debit' => '500.0000',
            'credit' => '0.0000',
        ])->assertCreated();

        $this->postJson('/api/parties/'.$manualUlid.'/opening-balances?type=account', [
            'opening_date' => '2026-01-01',
            'narration' => 'Cash opening',
            'debit' => '100.0000',
            'credit' => '0.0000',
        ])->assertCreated();

        $list = $this->getJson('/api/parties/'.$vendorUlid.'/opening-balances?type=vendor')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($openingUlid, $list[0]['ulid']);
        $this->assertNotEmpty($list[0]['leaf_account_ulid']);

        $posted = $this->postJson('/api/parties/'.$vendorUlid.'/opening-balances/'.$openingUlid.'/post?type=vendor')
            ->assertOk();
        $posted->assertJsonPath('status', 'posted');

        $entry = JournalEntry::query()->where('ulid', $openingUlid)->firstOrFail();
        $this->assertTrue($entry->isPosted());
        $this->assertSame(2, $entry->lines()->count());
        $debitSum = (string) $entry->lines()->sum('debit');
        $creditSum = (string) $entry->lines()->sum('credit');
        $this->assertSame(0, bccomp(bcadd($debitSum, '0', 4), bcadd($creditSum, '0', 4), 4));

        $this->patchJson('/api/parties/'.$vendorUlid.'/opening-balances/'.$openingUlid.'?type=vendor', [
            'credit' => '1.0000',
            'debit' => '0.0000',
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->deleteJson('/api/parties/'.$vendorUlid.'/opening-balances/'.$openingUlid.'?type=vendor')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $deletable = $this->postJson('/api/parties/'.$manualUlid.'/opening-balances?type=account', [
            'opening_date' => '2026-01-02',
            'narration' => 'Temp',
            'debit' => '25.0000',
            'credit' => '0.0000',
        ])->assertCreated()->json('ulid');

        $this->deleteJson('/api/parties/'.$manualUlid.'/opening-balances/'.$deletable.'?type=account')
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->assertFalse(JournalEntry::query()->where('ulid', $deletable)->exists());
    }

    public function test_post_requires_configured_equity_and_tenant_isolation(): void
    {
        $this->signInOwner('ob-iso-a')->assertOk();
        $coa = $this->seedCoa();

        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'V-ISO',
            'name' => 'Iso Vendor',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated()->json('ulid');

        $openingUlid = $this->postJson('/api/parties/'.$vendor.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'Needs equity',
            'debit' => '0.0000',
            'credit' => '50.0000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/parties/'.$vendor.'/opening-balances/'.$openingUlid.'/post?type=vendor')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'OPENING_EQUITY_ACCOUNT_REQUIRED');

        $this->configureEquityOffset($coa['equity']);
        $this->postJson('/api/parties/'.$vendor.'/opening-balances/'.$openingUlid.'/post?type=vendor')
            ->assertOk()
            ->assertJsonPath('status', 'posted');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('ob-iso-b')->assertOk();

        $this->getJson('/api/parties/'.$vendor.'/opening-balances?type=vendor')->assertNotFound();
        $this->postJson('/api/parties/'.$vendor.'/opening-balances?type=vendor', [
            'opening_date' => '2026-01-01',
            'narration' => 'Leak',
            'debit' => '0',
            'credit' => '1',
        ])->assertNotFound();
        $this->postJson('/api/parties/'.$vendor.'/opening-balances/'.$openingUlid.'/post?type=vendor')
            ->assertNotFound();
    }
}
