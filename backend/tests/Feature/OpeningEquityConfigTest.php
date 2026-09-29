<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OpeningEquityConfigTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{cash: string, equity: string}
     */
    private function seedTypes(): array
    {
        $assets = $this->postJson('/api/coa/main-heads', ['name' => 'ASSETS'])->assertCreated()->json('ulid');
        $equityMain = $this->postJson('/api/coa/main-heads', ['name' => 'EQUITY'])->assertCreated()->json('ulid');
        $current = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $assets,
            'name' => 'CURRENT',
        ])->assertCreated()->json('ulid');
        $cap = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $equityMain,
            'name' => 'CAPITAL',
        ])->assertCreated()->json('ulid');

        $cash = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $current,
            'code' => '0010',
            'name' => 'CASH',
            'is_cash' => true,
        ])->assertCreated()->json('ulid');
        $equity = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $cap,
            'code' => '0030',
            'name' => 'OPENING BALANCE EQUITY',
        ])->assertCreated()->json('ulid');

        return compact('cash', 'equity');
    }

    public function test_configure_opening_equity_account_validation_and_posting(): void
    {
        $this->signInOwner('ob-eq-cfg')->assertOk();
        $types = $this->seedTypes();

        $equity = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => '00002',
            'name' => 'OPENING ACCOUNT',
            'account_type_ulid' => $types['equity'],
        ])->assertCreated();
        $equityUlid = $equity->json('ulid');

        $cash = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => '00001',
            'name' => 'CASH IN HAND',
            'account_type_ulid' => $types['cash'],
        ])->assertCreated();
        $cashUlid = $cash->json('ulid');

        $shown = $this->getJson('/api/settings/business')->assertOk();
        $shown->assertJsonPath('opening_balance_equity_account_ulid', null);
        $this->assertNoInternalIds($shown->json());

        $leaves = $this->getJson('/api/coa/leaf-accounts')->assertOk()->json('data');
        $this->assertTrue(collect($leaves)->contains(fn ($row) => $row['ulid'] === $equityUlid));
        $this->assertNoInternalIds(['data' => $leaves]);

        $this->patchJson('/api/settings/business', [
            'opening_balance_equity_account_ulid' => '01INVALIDULIDNOTREAL00XXXX',
        ])->assertStatus(422);

        $this->patchJson('/api/settings/business', [
            'opening_balance_equity_account_ulid' => $equityUlid,
        ])->assertOk()
            ->assertJsonPath('opening_balance_equity_account_ulid', $equityUlid)
            ->assertJsonPath('opening_balance_equity_account.code', '00002')
            ->assertJsonPath('opening_balance_equity_account.name', 'OPENING ACCOUNT');

        $this->getJson('/api/settings/business')
            ->assertOk()
            ->assertJsonPath('opening_balance_equity_account_ulid', $equityUlid);

        Account::query()->where('ulid', $equityUlid)->update(['is_active' => false]);
        $this->patchJson('/api/settings/business', [
            'opening_balance_equity_account_ulid' => $equityUlid,
        ])->assertStatus(422);
        Account::query()->where('ulid', $equityUlid)->update(['is_active' => true]);

        $openingUlid = $this->postJson('/api/parties/'.$cashUlid.'/opening-balances?type=account', [
            'opening_date' => '2026-01-01',
            'narration' => 'Cash OB',
            'debit' => '100.0000',
            'credit' => '0.0000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/parties/'.$cashUlid.'/opening-balances/'.$openingUlid.'/post?type=account')
            ->assertOk()
            ->assertJsonPath('status', 'posted');
    }

    public function test_cross_tenant_equity_account_rejected(): void
    {
        $this->signInOwner('ob-eq-a')->assertOk();
        $types = $this->seedTypes();
        $equityUlid = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'EQ-A',
            'name' => 'Equity A',
            'account_type_ulid' => $types['equity'],
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('ob-eq-b')->assertOk();
        $this->seedTypes();

        $this->patchJson('/api/settings/business', [
            'opening_balance_equity_account_ulid' => $equityUlid,
        ])->assertStatus(422);

        $this->getJson('/api/settings/business')
            ->assertOk()
            ->assertJsonPath('opening_balance_equity_account_ulid', null);
    }
}
