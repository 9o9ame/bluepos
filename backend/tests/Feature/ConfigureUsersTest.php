<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\PartyProfile;
use App\Models\User;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConfigureUsersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_assign_tenant_salesman_to_membership(): void
    {
        $this->signInOwner('configure-users-assign')->assertOk();

        $context = app(TenantContext::class);
        $membership = $context->membership();
        $salesman = $this->createSalesman($context->tenantId(), 'SM-001', 'Sales One');

        $response = $this->putJson('/api/memberships/'.$membership->ulid.'/salesman', [
            'salesman_ulid' => $salesman->ulid,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertOk();

        $response
            ->assertJsonPath('ulid', $membership->ulid)
            ->assertJsonPath('salesman.ulid', $salesman->ulid)
            ->assertJsonPath('salesman.code', 'SM-001')
            ->assertJsonPath('salesman_start_time', '09:00:00')
            ->assertJsonPath('salesman_end_time', '18:00:00');

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->id,
            'salesman_party_profile_id' => $salesman->id,
            'salesman_start_time' => '09:00:00',
            'salesman_end_time' => '18:00:00',
        ]);

        $this->assertNoInternalIds($response->json());
    }

    public function test_salesman_options_are_tenant_scoped(): void
    {
        $this->signInOwner('configure-users-list')->assertOk();

        $tenantId = app(TenantContext::class)->tenantId();
        $visible = $this->createSalesman($tenantId, 'SM-LOCAL', 'Local Salesman');

        $foreignTenant = Tenant::factory()->create();
        $foreign = $this->createSalesman((int) $foreignTenant->id, 'SM-FOREIGN', 'Foreign Salesman');

        $response = $this->getJson('/api/memberships/salesmen')
            ->assertOk();

        $ulids = collect($response->json())->pluck('ulid');

        $this->assertTrue($ulids->contains($visible->ulid));
        $this->assertFalse($ulids->contains($foreign->ulid));
        $this->assertNoInternalIds($response->json());
    }

    public function test_cross_tenant_salesman_assignment_is_rejected(): void
    {
        $this->signInOwner('configure-users-cross')->assertOk();

        $membership = app(TenantContext::class)->membership();
        $foreignTenant = Tenant::factory()->create();
        $foreign = $this->createSalesman((int) $foreignTenant->id, 'SM-X', 'Foreign Salesman');

        $this->putJson('/api/memberships/'.$membership->ulid.'/salesman', [
            'salesman_ulid' => $foreign->ulid,
            'start_time' => '08:30',
            'end_time' => '17:30',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('memberships', [
            'id' => $membership->id,
            'salesman_party_profile_id' => $foreign->id,
        ]);
    }

    public function test_owner_can_save_and_clear_fingerprint_reference(): void
    {
        $this->signInOwner('configure-users-fp-save')->assertOk();

        $membership = app(TenantContext::class)->membership();

        $response = $this->putJson('/api/memberships/'.$membership->ulid.'/fingerprint', [
            'fingerprint_ref' => 'FP-001',
        ])->assertOk();

        $response
            ->assertJsonPath('ulid', $membership->ulid)
            ->assertJsonPath('fingerprint_ref', 'FP-001');

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->id,
            'fingerprint_ref' => 'FP-001',
        ]);
        $this->assertNoInternalIds($response->json());

        $this->putJson('/api/memberships/'.$membership->ulid.'/fingerprint', [
            'fingerprint_ref' => null,
        ])
            ->assertOk()
            ->assertJsonPath('fingerprint_ref', null);

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->id,
            'fingerprint_ref' => null,
        ]);
    }

    public function test_duplicate_fingerprint_reference_is_rejected_within_tenant(): void
    {
        $this->signInOwner('configure-users-fp-duplicate')->assertOk();

        $context = app(TenantContext::class);
        $ownerMembership = $context->membership();
        $otherMembership = Membership::factory()->create([
            'tenant_id' => $context->tenantId(),
            'user_id' => User::factory()->create()->id,
            'username' => 'fp-other',
        ]);

        $this->putJson('/api/memberships/'.$ownerMembership->ulid.'/fingerprint', [
            'fingerprint_ref' => 'FP-SHARED',
        ])->assertOk();

        $this->putJson('/api/memberships/'.$otherMembership->ulid.'/fingerprint', [
            'fingerprint_ref' => 'FP-SHARED',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertDatabaseHas('memberships', [
            'id' => $ownerMembership->id,
            'fingerprint_ref' => 'FP-SHARED',
        ]);
        $this->assertDatabaseHas('memberships', [
            'id' => $otherMembership->id,
            'fingerprint_ref' => null,
        ]);
    }

    private function createSalesman(int $tenantId, string $code, string $name): PartyProfile
    {
        $profile = PartyProfile::query()->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);

        $profile->types()->create(['type' => 'salesman']);

        return $profile;
    }
}
