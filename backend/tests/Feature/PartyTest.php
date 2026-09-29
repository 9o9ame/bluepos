<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{main: string, sub: string, ap: string, ar: string}
     */
    private function seedCoaTypes(): array
    {
        $main = $this->postJson('/api/coa/main-heads', [
            'name' => 'ASSETS',
            'sort_order' => 1,
        ])->assertCreated()->json('ulid');

        $liab = $this->postJson('/api/coa/main-heads', [
            'name' => 'LIABILITIES',
            'sort_order' => 2,
        ])->assertCreated()->json('ulid');

        $current = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $main,
            'name' => 'CURRENT ASSETS',
        ])->assertCreated()->json('ulid');

        $short = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $liab,
            'name' => 'SHORT TERM LIABILITIES',
        ])->assertCreated()->json('ulid');

        $ar = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $current,
            'name' => 'ACCOUNT RECEIVABLE',
            'is_receivable' => true,
        ])->assertCreated()->json('ulid');

        $ap = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $short,
            'name' => 'ACCOUNT PAYABLE',
            'is_payable' => true,
        ])->assertCreated()->json('ulid');

        return ['main' => $main, 'sub' => $current, 'ap' => $ap, 'ar' => $ar];
    }

    public function test_parties_list_create_update_account_type_and_type_filter(): void
    {
        $this->signInOwner('party-crud')->assertOk();
        $coa = $this->seedCoaTypes();

        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'v-01',
            'name' => 'Food Panda Charges',
            'deals_in' => 'Delivery',
            'contact_person' => 'Ali',
            'mobile' => '0300-1111111',
            'mobile_secondary' => '0300-2222222',
            'phone' => '021-1111111',
            'phone_secondary' => '021-2222222',
            'email' => 'vendor@example.com',
            'address' => 'Karachi',
            'billing_address' => 'Karachi Bill',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated();

        $vendor->assertJsonPath('party_type', 'vendor');
        $vendor->assertJsonPath('code', 'V-01');
        $vendor->assertJsonPath('account_type_ulid', $coa['ap']);
        $vendor->assertJsonPath('account_type.name', 'ACCOUNT PAYABLE');
        $this->assertNoInternalIds($vendor->json());
        $vendorUlid = $vendor->json('ulid');

        $this->assertTrue(
            Supplier::query()->where('ulid', $vendorUlid)->exists(),
            'Vendor parties must persist in suppliers table',
        );
        $this->assertTrue(AuditLog::query()->where('event', 'SUPPLIER_CREATED')->where('resource_ulid', $vendorUlid)->exists());

        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'c-01',
            'name' => 'Walk-in Cafe',
            'address' => 'Lahore',
            'mobile' => '0301-3333333',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated();
        $customer->assertJsonPath('party_type', 'customer');
        $customer->assertJsonPath('account_type_ulid', $coa['ar']);
        $customerUlid = $customer->json('ulid');

        $ledger = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'cash-1',
            'name' => 'Till Cash',
            'address' => 'Counter',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated();
        $ledger->assertJsonPath('party_type', 'account');
        $ledgerUlid = $ledger->json('ulid');
        $this->assertTrue(Account::query()->where('ulid', $ledgerUlid)->exists());

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'v-02',
            'name' => 'No Type Vendor',
        ])->assertStatus(422);

        $all = $this->getJson('/api/parties?type=all')->assertOk()->json('data');
        $ulids = collect($all)->pluck('ulid')->all();
        $this->assertContains($vendorUlid, $ulids);
        $this->assertContains($customerUlid, $ulids);
        $this->assertContains($ledgerUlid, $ulids);

        $vendorsOnly = $this->getJson('/api/parties?type=vendor')->assertOk()->json('data');
        $this->assertContains($vendorUlid, collect($vendorsOnly)->pluck('ulid')->all());
        $this->assertNotContains($customerUlid, collect($vendorsOnly)->pluck('ulid')->all());
        $this->assertNotContains($ledgerUlid, collect($vendorsOnly)->pluck('ulid')->all());

        $customersOnly = $this->getJson('/api/parties?type=customer')->assertOk()->json('data');
        $this->assertContains($customerUlid, collect($customersOnly)->pluck('ulid')->all());

        $accountsOnly = $this->getJson('/api/parties?type=account')->assertOk()->json('data');
        $this->assertContains($ledgerUlid, collect($accountsOnly)->pluck('ulid')->all());
        $this->assertNotContains($vendorUlid, collect($accountsOnly)->pluck('ulid')->all());

        $salesmen = $this->getJson('/api/parties?type=salesman')->assertOk()->json('data');
        $this->assertSame([], $salesmen);

        $updated = $this->patchJson('/api/parties/'.$vendorUlid, [
            'party_type' => 'vendor',
            'name' => 'Food Panda Charges Ltd',
            'is_active' => false,
            'account_type_ulid' => $coa['ap'],
        ])->assertOk();
        $updated->assertJsonPath('name', 'Food Panda Charges Ltd');
        $updated->assertJsonPath('is_active', false);

        $supplierList = $this->getJson('/api/suppliers')->assertOk()->json();
        $matched = collect($supplierList)->firstWhere('ulid', $vendorUlid);
        $this->assertNotNull($matched);
        $this->assertSame('Food Panda Charges Ltd', $matched['name']);
        $this->assertFalse($matched['is_active']);

        $this->deleteJson('/api/parties/'.$customerUlid.'?type=customer')
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/parties/'.$customerUlid.'?type=customer')
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_party_vendor_shares_supplier_source_and_tenant_isolation(): void
    {
        $this->signInOwner('party-iso-a')->assertOk();
        $coa = $this->seedCoaTypes();
        $ulidA = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'SHARED',
            'name' => 'Tenant A Vendor',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'SHARED',
            'name' => 'Dup',
            'account_type_ulid' => $coa['ap'],
        ])->assertStatus(422);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('party-iso-b')->assertOk();
        $coaB = $this->seedCoaTypes();

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'SHARED',
            'name' => 'Tenant B Vendor',
            'account_type_ulid' => $coaB['ap'],
        ])->assertCreated();

        $list = $this->getJson('/api/parties?type=vendor')->assertOk()->json('data');
        $this->assertNotContains($ulidA, collect($list)->pluck('ulid')->all());
        $this->getJson('/api/parties/'.$ulidA.'?type=vendor')->assertNotFound();
        $this->patchJson('/api/parties/'.$ulidA, [
            'party_type' => 'vendor',
            'name' => 'Leaked',
        ])->assertNotFound();
    }

    public function test_unsupported_party_types_cannot_be_created(): void
    {
        $this->signInOwner('party-unsupported')->assertOk();
        $coa = $this->seedCoaTypes();

        $this->postJson('/api/parties', [
            'party_type' => 'salesman',
            'code' => 'SM1',
            'name' => 'Ahmad',
            'account_type_ulid' => $coa['ar'],
        ])->assertStatus(422);
    }
}
