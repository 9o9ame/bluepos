<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartyProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_party_can_be_vendor_customer_and_salesman_without_duplicate_identity(): void
    {
        $this->signInOwner('party-profile-multi')->assertOk();

        $payable = $this->accountTypeUlid('payable');
        $receivable = $this->accountTypeUlid('receivable');

        $response = $this->postJson('/api/party-profiles', [
            'party_types' => ['vendor', 'customer', 'salesman'],
            'primary_type' => 'customer',
            'code' => 'MULTI-001',
            'name' => 'Multi Role Party',
            'vendor_account_type_ulid' => $payable,
            'customer_account_type_ulid' => $receivable,
            'mobile' => '03001234567',
        ])->assertCreated()
            ->assertJsonPath('party_type', 'customer')
            ->assertJsonPath('code', 'MULTI-001')
            ->assertJsonPath('name', 'Multi Role Party');

        $identityUlid = (string) $response->json('identity_ulid');
        $vendorUlid = (string) $response->json('vendor_ulid');
        $customerUlid = (string) $response->json('customer_ulid');

        $this->assertNotSame('', $identityUlid);
        $this->assertNotSame('', $vendorUlid);
        $this->assertNotSame('', $customerUlid);

        $profile = PartyProfile::query()->where('ulid', $identityUlid)->firstOrFail();
        $supplier = Supplier::query()->where('ulid', $vendorUlid)->firstOrFail();
        $customer = Customer::query()->where('ulid', $customerUlid)->firstOrFail();

        $this->assertSame($profile->id, $supplier->party_profile_id);
        $this->assertSame($profile->id, $customer->party_profile_id);
        $this->assertSame(
            ['customer', 'salesman', 'vendor'],
            $profile->types()->orderBy('type')->pluck('type')->all(),
        );

        $this->assertDatabaseHas('accounts', [
            'tenant_id' => $profile->tenant_id,
            'supplier_id' => $supplier->id,
            'code' => 'MULTI-001-V',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('accounts', [
            'tenant_id' => $profile->tenant_id,
            'customer_id' => $customer->id,
            'code' => 'MULTI-001-C',
            'is_active' => true,
        ]);

        $this->getJson('/api/party-profiles?type=salesman')
            ->assertOk()
            ->assertJsonFragment(['identity_ulid' => $identityUlid, 'name' => 'Multi Role Party']);

        $this->getJson('/api/party-profiles?type=vendor')
            ->assertOk()
            ->assertJsonFragment(['vendor_ulid' => $vendorUlid]);

        $this->getJson('/api/party-profiles?type=customer')
            ->assertOk()
            ->assertJsonFragment(['customer_ulid' => $customerUlid]);
    }

    public function test_removing_vendor_type_preserves_history_and_deactivates_vendor_backing(): void
    {
        $this->signInOwner('party-profile-remove')->assertOk();

        $payable = $this->accountTypeUlid('ACCOUNT PAYABLE');
        $receivable = $this->accountTypeUlid('ACCOUNT RECEIVABLE');

        $created = $this->postJson('/api/party-profiles', [
            'party_types' => ['vendor', 'customer', 'salesman'],
            'primary_type' => 'vendor',
            'code' => 'MULTI-002',
            'name' => 'Role Removal Party',
            'vendor_account_type_ulid' => $payable,
            'customer_account_type_ulid' => $receivable,
        ])->assertCreated();

        $identityUlid = (string) $created->json('identity_ulid');
        $vendorUlid = (string) $created->json('vendor_ulid');
        $customerUlid = (string) $created->json('customer_ulid');

        $updated = $this->patchJson('/api/party-profiles/'.$identityUlid, [
            'party_types' => ['customer', 'salesman'],
            'primary_type' => 'customer',
            'code' => 'MULTI-002',
            'name' => 'Role Removal Party',
            'customer_account_type_ulid' => $receivable,
        ])->assertOk();

        $updated->assertJsonMissing(['vendor' => true]);
        $this->assertFalse((bool) Supplier::query()->where('ulid', $vendorUlid)->value('is_active'));
        $this->assertTrue((bool) Customer::query()->where('ulid', $customerUlid)->value('is_active'));

        $customer = Customer::query()->where('ulid', $customerUlid)->firstOrFail();
        $vendor = Supplier::query()->where('ulid', $vendorUlid)->firstOrFail();

        $this->assertDatabaseHas('accounts', [
            'customer_id' => $customer->id,
            'code' => 'MULTI-002',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('accounts', [
            'supplier_id' => $vendor->id,
            'code' => 'MULTI-002-V',
            'is_active' => false,
        ]);

        $this->getJson('/api/party-profiles?type=vendor')
            ->assertOk()
            ->assertJsonMissing(['identity_ulid' => $identityUlid]);

        $this->getJson('/api/party-profiles?type=salesman')
            ->assertOk()
            ->assertJsonFragment(['identity_ulid' => $identityUlid]);
    }

    public function test_party_profile_is_tenant_isolated(): void
    {
        $this->signInOwner('party-profile-tenant-a')->assertOk();

        $created = $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-ONLY',
            'name' => 'Tenant A Salesman',
        ])->assertCreated();

        $identityUlid = (string) $created->json('identity_ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('party-profile-tenant-b')->assertOk();

        $this->getJson('/api/party-profiles/'.$identityUlid)->assertNotFound();
        $this->patchJson('/api/party-profiles/'.$identityUlid, [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-ONLY',
            'name' => 'Should Not Update',
        ])->assertNotFound();
    }

    private function accountTypeUlid(string $kind): string
    {
        $tenantId = app(TenantContext::class)->tenantId();

        $query = AccountType::query()
            ->forTenant($tenantId)
            ->where('is_active', true);

        if ($kind === 'payable') {
            $query->where('is_payable', true);
        } else {
            $query->where('is_receivable', true);
        }

        $ulid = $query->value('ulid');

        $this->assertNotNull($ulid, 'Missing active '.$kind.' account type.');

        return (string) $ulid;
    }
}
