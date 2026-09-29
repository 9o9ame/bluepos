<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartyBankAndOthersTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{ap: string, vendor: string}
     */
    private function seedVendor(): array
    {
        $main = $this->postJson('/api/coa/main-heads', ['name' => 'LIABILITIES'])->assertCreated()->json('ulid');
        $sub = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $main,
            'name' => 'SHORT TERM',
        ])->assertCreated()->json('ulid');
        $ap = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $sub,
            'name' => 'ACCOUNT PAYABLE',
            'is_payable' => true,
        ])->assertCreated()->json('ulid');

        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'BANK-V1',
            'name' => 'Bank Vendor',
            'account_type_ulid' => $ap,
            'license_number' => 'LIC-100',
            'license_issued_on' => '2026-01-15',
            'license_type' => 'A',
            'license_expires_on' => '2027-01-15',
            'ignore_warranty' => true,
            'print_license' => false,
            'rf_id' => 'RF-9',
            'store_allowed' => 'ALL',
        ])->assertCreated();

        $vendor->assertJsonPath('license_number', 'LIC-100');
        $vendor->assertJsonPath('license_issued_on', '2026-01-15');
        $vendor->assertJsonPath('store_allowed', 'ALL');
        $vendor->assertJsonPath('ignore_warranty', true);

        return ['ap' => $ap, 'vendor' => $vendor->json('ulid')];
    }

    public function test_bank_accounts_crud_others_and_tenant_isolation(): void
    {
        $this->signInOwner('party-bank-a')->assertOk();
        $seed = $this->seedVendor();
        $vendorUlid = $seed['vendor'];

        $created = $this->postJson('/api/parties/'.$vendorUlid.'/bank-accounts?type=vendor', [
            'bank_name' => 'HBL',
            'branch_name' => 'Clifton',
            'branch_code' => '001',
            'city' => 'Karachi',
            'account_number' => '1234567890',
        ])->assertCreated();
        $created->assertJsonPath('bank_name', 'HBL');
        $this->assertNoInternalIds($created->json());
        $bankUlid = $created->json('ulid');

        $this->postJson('/api/parties/'.$vendorUlid.'/bank-accounts?type=vendor', [
            'bank_name' => 'HBL 2',
            'account_number' => '1234567890',
        ])->assertStatus(422);

        $list = $this->getJson('/api/parties/'.$vendorUlid.'/bank-accounts?type=vendor')
            ->assertOk()
            ->json();
        $this->assertCount(1, $list);
        $this->assertSame($bankUlid, $list[0]['ulid']);

        $this->patchJson('/api/parties/'.$vendorUlid.'/bank-accounts/'.$bankUlid.'?type=vendor', [
            'city' => 'Lahore',
        ])->assertOk()->assertJsonPath('city', 'Lahore');

        $this->patchJson('/api/parties/'.$vendorUlid, [
            'party_type' => 'vendor',
            'rf_id' => 'RF-UPDATED',
            'store_allowed' => 'BRANCH',
            'print_license' => true,
        ])->assertOk()
            ->assertJsonPath('rf_id', 'RF-UPDATED')
            ->assertJsonPath('store_allowed', 'BRANCH')
            ->assertJsonPath('print_license', true);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('party-bank-b')->assertOk();

        $this->getJson('/api/parties/'.$vendorUlid.'/bank-accounts?type=vendor')->assertNotFound();
        $this->postJson('/api/parties/'.$vendorUlid.'/bank-accounts?type=vendor', [
            'bank_name' => 'Hack',
            'account_number' => '999',
        ])->assertNotFound();
        $this->patchJson('/api/parties/'.$vendorUlid.'/bank-accounts/'.$bankUlid.'?type=vendor', [
            'city' => 'Leaked',
        ])->assertNotFound();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('party-bank-a', 'owner')->assertOk();

        $this->deleteJson('/api/parties/'.$vendorUlid.'/bank-accounts/'.$bankUlid.'?type=vendor')
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->getJson('/api/parties/'.$vendorUlid.'/bank-accounts?type=vendor')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_customer_others_and_manual_account_bank_accounts(): void
    {
        $this->signInOwner('party-bank-cust')->assertOk();
        $main = $this->postJson('/api/coa/main-heads', ['name' => 'ASSETS'])->assertCreated()->json('ulid');
        $sub = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $main,
            'name' => 'CURRENT',
        ])->assertCreated()->json('ulid');
        $ar = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $sub,
            'name' => 'RECEIVABLE',
            'is_receivable' => true,
        ])->assertCreated()->json('ulid');
        $cash = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $sub,
            'name' => 'CASH',
            'is_cash' => true,
        ])->assertCreated()->json('ulid');

        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'C-BANK',
            'name' => 'Retail Customer',
            'account_type_ulid' => $ar,
            'license_number' => 'C-LIC',
            'license_type' => 'B',
            'store_allowed' => 'BRANCH',
        ])->assertCreated();
        $customerUlid = $customer->json('ulid');
        $customer->assertJsonPath('license_number', 'C-LIC');
        $customer->assertJsonPath('license_type', 'B');

        $this->postJson('/api/parties/'.$customerUlid.'/bank-accounts?type=customer', [
            'bank_name' => 'MCB',
            'account_number' => '555',
        ])->assertCreated();

        $this->getJson('/api/parties/'.$customerUlid.'/bank-accounts?type=customer')
            ->assertOk()
            ->assertJsonCount(1);

        $this->postJson('/api/parties/'.$customerUlid.'/bank-accounts?type=customer', [
            'bank_name' => '',
            'account_number' => '556',
        ])->assertStatus(422);

        $account = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'ACC-BANK',
            'name' => 'Petty Cash',
            'account_type_ulid' => $cash,
        ])->assertCreated();
        $accountUlid = $account->json('ulid');
        $this->assertNull($account->json('license_number'));

        $bank = $this->postJson('/api/parties/'.$accountUlid.'/bank-accounts?type=account', [
            'bank_name' => 'NBP',
            'branch_name' => 'Main',
            'account_number' => 'ACC-001',
        ])->assertCreated();
        $this->assertNoInternalIds($bank->json());
    }
}
