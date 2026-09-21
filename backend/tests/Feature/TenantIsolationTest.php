<?php

namespace Tests\Feature;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_cannot_access_another_tenant(): void
    {
        $tenantA = $this->signInOwner('juliet')->assertOk();
        $tenantAUlid = $tenantA->json('tenant.ulid');
        $tenantABranch = $tenantA->json('branch.ulid');

        $this->postJson('/api/auth/logout')->assertOk();

        $tenantB = $this->signInOwner('kilo')->assertOk();

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertSame($tenantB->json('tenant.ulid'), $me->json('tenant.ulid'));
        $this->assertNotSame($tenantAUlid, $me->json('tenant.ulid'));

        $branches = $this->getJson('/api/branches')->assertOk()->json();
        $ulids = collect($branches)->pluck('ulid')->all();

        $this->assertContains($tenantB->json('branch.ulid'), $ulids);
        $this->assertNotContains($tenantABranch, $ulids);
        $this->assertNoInternalIds($branches);
    }

    public function test_user_cannot_switch_to_another_tenants_branch(): void
    {
        $tenantA = $this->signInOwner('lima')->assertOk();
        $foreignBranchUlid = $tenantA->json('branch.ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('mike')->assertOk();

        $this->postJson("/api/branches/{$foreignBranchUlid}/switch")
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('tenant.name', 'Mart mike');
    }

    public function test_owner_can_switch_to_own_branch(): void
    {
        $register = $this->signInOwner('own-switch')->assertOk();
        $branchUlid = $register->json('branch.ulid');

        $switched = $this->postJson("/api/branches/{$branchUlid}/switch")->assertOk();

        $switched->assertJsonPath('branch.ulid', $branchUlid)
            ->assertJsonPath('tenant.ulid', $register->json('tenant.ulid'));
        $this->assertNoInternalIds($switched->json());
        $this->assertSame($branchUlid, $this->getJson('/api/auth/me')->json('branch.ulid'));
    }

    public function test_public_api_does_not_expose_bigint_ids(): void
    {
        $register = $this->signInOwner('november')->assertOk();
        $this->assertNoInternalIds($register->json());

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertNoInternalIds($me->json());

        $branches = $this->getJson('/api/branches')->assertOk();
        $this->assertNoInternalIds($branches->json());

        $switch = $this->postJson('/api/branches/'.$register->json('branch.ulid').'/switch')->assertOk();
        $this->assertNoInternalIds($switch->json());
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $register->json('user.ulid'));
    }

    public function test_duplicate_tenant_branch_code_is_prevented(): void
    {
        $this->signInOwner('oscar')->assertOk();
        $tenant = Tenant::query()->where('name', 'Mart oscar')->firstOrFail();

        try {
            Branch::query()->create([
                'tenant_id' => $tenant->id,
                'code' => 'MAIN',
                'name' => 'Duplicate Main',
                'status' => BranchStatus::Active,
                'is_default' => false,
            ]);
            $this->fail('Duplicate branch code should be rejected by the database.');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }
    }

    public function test_same_branch_code_may_exist_in_different_tenants(): void
    {
        $first = $this->signInOwner('papa')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $second = $this->signInOwner('quebec')->assertOk();

        $this->assertSame('MAIN', $first->json('branch.code'));
        $this->assertSame('MAIN', $second->json('branch.code'));
        $this->assertNotSame($first->json('branch.ulid'), $second->json('branch.ulid'));
        $this->assertSame(2, Branch::query()->where('code', 'MAIN')->count());
    }
}
