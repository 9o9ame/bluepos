<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_parties_list_create_update_and_type_filter(): void
    {
        $this->signInOwner('party-crud')->assertOk();

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
        ])->assertCreated();

        $vendor->assertJsonPath('party_type', 'vendor');
        $vendor->assertJsonPath('code', 'V-01');
        $vendor->assertJsonPath('name', 'Food Panda Charges');
        $vendor->assertJsonPath('billing_address', 'Karachi Bill');
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
        ])->assertCreated();
        $customer->assertJsonPath('party_type', 'customer');
        $customerUlid = $customer->json('ulid');
        $this->assertTrue(AuditLog::query()->where('event', 'CUSTOMER_CREATED')->where('resource_ulid', $customerUlid)->exists());

        $all = $this->getJson('/api/parties?type=all')->assertOk()->json('data');
        $this->assertContains($vendorUlid, collect($all)->pluck('ulid')->all());
        $this->assertContains($customerUlid, collect($all)->pluck('ulid')->all());

        $vendorsOnly = $this->getJson('/api/parties?type=vendor')->assertOk()->json('data');
        $this->assertContains($vendorUlid, collect($vendorsOnly)->pluck('ulid')->all());
        $this->assertNotContains($customerUlid, collect($vendorsOnly)->pluck('ulid')->all());

        $customersOnly = $this->getJson('/api/parties?type=customer')->assertOk()->json('data');
        $this->assertContains($customerUlid, collect($customersOnly)->pluck('ulid')->all());
        $this->assertNotContains($vendorUlid, collect($customersOnly)->pluck('ulid')->all());

        $accounts = $this->getJson('/api/parties?type=account')->assertOk()->json('data');
        $this->assertSame([], $accounts);

        $updated = $this->patchJson('/api/parties/'.$vendorUlid, [
            'party_type' => 'vendor',
            'name' => 'Food Panda Charges Ltd',
            'is_active' => false,
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
        $ulidA = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'SHARED',
            'name' => 'Tenant A Vendor',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'SHARED',
            'name' => 'Dup',
        ])->assertStatus(422);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('party-iso-b')->assertOk();

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'SHARED',
            'name' => 'Tenant B Vendor',
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

        $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'ACC1',
            'name' => 'Cash',
        ])->assertStatus(422);

        $this->postJson('/api/parties', [
            'party_type' => 'salesman',
            'code' => 'SM1',
            'name' => 'Ahmad',
        ])->assertStatus(422);
    }
}
