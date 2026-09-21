<?php

namespace Tests\Feature;

use App\Authz\FinalOwnerGuard;
use App\Authz\PermissionCatalogue;
use App\Authz\PermissionCatalogSync;
use App\Enums\BranchStatus;
use App\Enums\MembershipStatus;
use App\Enums\WarehouseStatus;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_roles_are_provisioned_and_owner_assigned(): void
    {
        $response = $this->signInOwner('rbac-defaults')->assertOk();

        $tenant = Tenant::query()->where('name', 'Mart rbac-defaults')->firstOrFail();
        $codes = Role::query()->forTenant($tenant->id)->orderBy('code')->pluck('code')->all();

        $this->assertSame(['accountant', 'admin', 'cashier', 'manager', 'owner', 'purchase', 'stock'], $codes);
        $this->assertSame(7, Role::query()->forTenant($tenant->id)->whereNotNull('tenant_id')->count());
        $this->assertTrue($response->json('membership.is_owner'));
        $this->assertContains('owner', $response->json('roles.*.code'));
        $this->assertContains('users.manage_roles', $response->json('permissions'));
        $this->assertContains('users.manage_branches', $response->json('permissions'));
        $this->assertContains('roles.manage_permissions', $response->json('permissions'));
        $this->assertSame('all_branches', $response->json('branch_access'));
        $this->assertNoInternalIds($response->json());
        $this->assertNoInternalIds($this->getJson('/api/permissions')->assertOk()->json());
        $this->assertNoInternalIds($this->getJson('/api/memberships')->assertOk()->json());

        $membership = Membership::query()->where('ulid', $response->json('membership.ulid'))->firstOrFail();
        $this->assertTrue($membership->roles()->where('code', PermissionCatalogue::OWNER)->exists());
        $this->assertSame($tenant->id, $membership->roles()->where('code', PermissionCatalogue::OWNER)->firstOrFail()->tenant_id);
    }

    public function test_permission_catalogue_sync_is_idempotent_and_platform_defined(): void
    {
        $sync = app(PermissionCatalogSync::class);
        $sync->ensure();

        $definitions = collect(PermissionCatalogue::definitions());
        $expectedKeys = $definitions->pluck('key');

        $this->assertSame($expectedKeys->count(), $expectedKeys->unique()->count());

        foreach ($definitions as $definition) {
            $this->assertDatabaseHas('permissions', [
                'key' => $definition['key'],
                'name' => $definition['name'],
                'module' => $definition['module'],
                'is_platform' => $definition['is_platform'],
            ]);
        }

        $count = Permission::query()->whereIn('key', $expectedKeys)->count();
        $this->assertSame($expectedKeys->count(), $count);

        $sync->ensure();

        $this->assertSame($count, Permission::query()->whereIn('key', $expectedKeys)->count());
        $this->assertSame(
            $expectedKeys->count(),
            Permission::query()->whereIn('key', $expectedKeys)->distinct()->count('key'),
        );
    }

    public function test_default_roles_are_tenant_scoped_and_codes_may_repeat(): void
    {
        $this->signInOwner('rbac-a')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('rbac-b')->assertOk();

        $this->assertSame(2, Role::query()->where('code', 'owner')->count());
        $this->assertSame(14, Role::query()->count());
    }

    public function test_duplicate_role_code_is_rejected_in_same_tenant(): void
    {
        $this->signInOwner('rbac-dup')->assertOk();

        $this->postJson('/api/roles', [
            'name' => 'Owner Copy',
            'code' => 'owner',
        ])->assertUnprocessable();

        $this->postJson('/api/roles', [
            'name' => 'Bookkeeper',
            'code' => 'bookkeeper',
            'branch_access' => 'selected_branches',
        ])->assertCreated();

        $this->postJson('/api/roles', [
            'name' => 'Bookkeeper 2',
            'code' => 'bookkeeper',
        ])->assertUnprocessable();
    }

    public function test_owner_can_create_custom_role_and_assign_valid_permissions(): void
    {
        $this->signInOwner('rbac-custom')->assertOk();

        $created = $this->postJson('/api/roles', [
            'name' => 'Stock Manager',
            'code' => 'stock-manager',
            'description' => 'Inventory lead',
            'branch_access' => 'selected_branches',
        ])->assertCreated();

        $this->assertNoInternalIds($created->json());

        $synced = $this->putJson('/api/roles/'.$created->json('ulid').'/permissions', [
            'permissions' => ['inventory.view', 'inventory.adjust', 'dashboard.view'],
        ])->assertOk()->json('permissions');

        $this->assertContains('inventory.view', collect($synced)->pluck('key')->all());
        $this->assertNoInternalIds($synced);

        $this->putJson('/api/roles/'.$created->json('ulid').'/permissions', [
            'permissions' => ['inventory.view', 'not.a.real.permission'],
        ])->assertUnprocessable();

        $this->putJson('/api/roles/'.$created->json('ulid').'/permissions', [
            'permissions' => ['platform.tenants.manage'],
        ])->assertUnprocessable();
    }

    public function test_system_roles_cannot_be_deleted_or_renamed(): void
    {
        $this->signInOwner('rbac-system')->assertOk();
        $ownerRole = $this->roleUlid('owner');

        $this->patchJson('/api/roles/'.$ownerRole, ['name' => 'Super Owner'])
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SYSTEM_ROLE_PROTECTED');

        $this->deleteJson('/api/roles/'.$ownerRole)
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SYSTEM_ROLE_PROTECTED');
    }

    public function test_cross_tenant_role_access_is_blocked(): void
    {
        $tenantA = $this->signInOwner('rbac-iso-a')->assertOk();
        $roleA = $this->roleUlid('cashier');
        $this->postJson('/api/auth/logout')->assertOk();

        $this->signInOwner('rbac-iso-b')->assertOk();

        $this->getJson('/api/roles/'.$roleA)
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');
        $this->patchJson('/api/roles/'.$roleA, ['description' => 'hack'])
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');
        $this->deleteJson('/api/roles/'.$roleA)
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');
        $this->putJson('/api/roles/'.$roleA.'/permissions', [
            'permissions' => ['dashboard.view'],
        ])->assertNotFound()->assertJsonPath('error.key', 'NOT_FOUND');

        $membershipA = $tenantA->json('membership.ulid');
        $this->putJson('/api/memberships/'.$membershipA.'/roles', [
            'roles' => [$this->roleUlid('cashier')],
        ])->assertNotFound()->assertJsonPath('error.key', 'NOT_FOUND');
        $this->getJson('/api/memberships/'.$membershipA)
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');
        $this->postJson('/api/memberships/'.$membershipA.'/deactivate')
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');

        $ownMembership = $this->getJson('/api/auth/me')->assertOk()->json('membership.ulid');
        $this->putJson('/api/memberships/'.$ownMembership.'/roles', [
            'roles' => [$roleA],
        ])->assertUnprocessable()->assertJsonPath('error.key', 'VALIDATION_ERROR');
        $this->putJson('/api/memberships/'.$ownMembership.'/branches', [
            'branches' => [$tenantA->json('branch.ulid')],
        ])->assertUnprocessable()->assertJsonPath('error.key', 'VALIDATION_ERROR');
        $this->assertNoInternalIds($this->getJson('/api/roles')->assertOk()->json());
    }

    public function test_unauthorized_user_cannot_create_role_or_assign_roles_and_branches(): void
    {
        $owner = $this->signInOwner('rbac-unauth')->assertOk();
        $ownerRole = $this->roleUlid(PermissionCatalogue::OWNER);
        $cashierRole = $this->roleUlid(PermissionCatalogue::CASHIER);
        $this->createRestrictedMember('rbac-unauth', PermissionCatalogue::CASHIER, $owner->json('branch.ulid'));

        $this->postJson('/api/auth/logout')->assertOk();
        $cashierSession = $this->loginAs('rbac-unauth', 'cashier-rbac-unauth')->assertOk();

        $this->postJson('/api/roles', [
            'name' => 'Hacker',
            'code' => 'hacker',
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');

        $this->putJson('/api/roles/'.$cashierRole.'/permissions', [
            'permissions' => ['dashboard.view'],
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');

        $this->putJson('/api/memberships/'.$cashierSession->json('membership.ulid').'/roles', [
            'roles' => [$ownerRole],
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');

        $this->putJson('/api/memberships/'.$cashierSession->json('membership.ulid').'/branches', [
            'branches' => [$owner->json('branch.ulid')],
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
    }

    public function test_restricted_membership_branch_switching(): void
    {
        $foreign = $this->signInOwner('rbac-branch-x')->assertOk();
        $foreignBranch = $foreign->json('branch.ulid');
        $this->postJson('/api/auth/logout')->assertOk();

        $owner = $this->signInOwner('rbac-branch')->assertOk();
        $tenant = Tenant::query()->where('name', 'Mart rbac-branch')->firstOrFail();
        $other = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => 'EAST',
            'name' => 'East',
            'status' => BranchStatus::Active,
            'is_default' => false,
        ]);
        Warehouse::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $other->id,
            'code' => 'EAST',
            'name' => 'East Warehouse',
            'status' => WarehouseStatus::Active,
            'is_default' => true,
        ]);

        $this->createRestrictedMember('rbac-branch', PermissionCatalogue::CASHIER, $owner->json('branch.ulid'));
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('rbac-branch', 'cashier-rbac-branch')->assertOk();

        $this->postJson('/api/branches/'.$other->ulid.'/switch')
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');

        $this->postJson('/api/branches/'.$foreignBranch.'/switch')
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');

        $this->postJson('/api/branches/'.$owner->json('branch.ulid').'/switch')
            ->assertOk()
            ->assertJsonPath('branch.ulid', $owner->json('branch.ulid'));
    }

    public function test_client_tenant_id_cannot_override_role_assignment(): void
    {
        $first = $this->signInOwner('rbac-override')->assertOk();
        $membershipUlid = $first->json('membership.ulid');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('rbac-override-b')->assertOk();

        $this->putJson('/api/memberships/'.$membershipUlid.'/roles', [
            'tenant_id' => 1,
            'roles' => [$this->roleUlid('owner')],
        ])->assertNotFound();
    }

    public function test_final_owner_cannot_be_removed_or_deactivated(): void
    {
        $owner = $this->signInOwner('rbac-last')->assertOk();
        $membershipUlid = $owner->json('membership.ulid');
        $cashier = $this->roleUlid('cashier');

        $this->putJson('/api/memberships/'.$membershipUlid.'/roles', [
            'roles' => [$cashier],
        ])->assertForbidden()->assertJsonPath('error.key', 'FINAL_OWNER_REQUIRED');

        $this->postJson('/api/memberships/'.$membershipUlid.'/deactivate')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FINAL_OWNER_REQUIRED');
    }

    public function test_second_owner_may_be_demoted_and_locks_are_used(): void
    {
        $owner = $this->signInOwner('rbac-two')->assertOk();
        $ownerRole = $this->roleUlid('owner');
        $cashier = $this->roleUlid('cashier');
        $branch = $owner->json('branch.ulid');

        $second = $this->postJson('/api/memberships', [
            'name' => 'Second Owner',
            'username' => 'second-rbac-two',
            'recovery_email' => 'second-rbac-two@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$ownerRole],
            'branches' => [$branch],
        ])->assertCreated();

        $this->assertTrue($second->json('is_owner'));
        $this->assertDatabaseHas('memberships', [
            'ulid' => $second->json('ulid'),
            'is_owner' => true,
        ]);

        $this->putJson('/api/memberships/'.$second->json('ulid').'/roles', [
            'roles' => [$cashier],
        ])->assertOk()->assertJsonPath('is_owner', false);

        $this->assertDatabaseHas('memberships', [
            'ulid' => $second->json('ulid'),
            'is_owner' => false,
        ]);
        $this->assertFalse(
            Membership::query()->where('ulid', $second->json('ulid'))->firstOrFail()
                ->hasRoleCode(PermissionCatalogue::OWNER),
        );

        $tenant = Tenant::query()->where('name', 'Mart rbac-two')->firstOrFail();
        $locked = app(FinalOwnerGuard::class)->lockActiveOwners($tenant->id);
        $this->assertCount(1, $locked);

        $this->putJson('/api/memberships/'.$owner->json('membership.ulid').'/roles', [
            'roles' => [$cashier],
        ])->assertForbidden()->assertJsonPath('error.key', 'FINAL_OWNER_REQUIRED');
    }

    public function test_second_owner_may_be_deactivated(): void
    {
        $owner = $this->signInOwner('rbac-two-off')->assertOk();
        $ownerRole = $this->roleUlid('owner');

        $second = $this->postJson('/api/memberships', [
            'name' => 'Second Owner',
            'username' => 'second-rbac-two-off',
            'recovery_email' => 'second-rbac-two-off@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$ownerRole],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->assertTrue($second->json('is_owner'));
        $this->assertNoInternalIds($second->json());
        $this->assertDatabaseHas('memberships', [
            'ulid' => $second->json('ulid'),
            'is_owner' => true,
        ]);
        $this->assertTrue(
            Membership::query()->where('ulid', $second->json('ulid'))->firstOrFail()
                ->hasRoleCode(PermissionCatalogue::OWNER),
        );

        $this->postJson('/api/memberships/'.$second->json('ulid').'/deactivate')
            ->assertOk()
            ->assertJsonPath('status', 'suspended');

        $remaining = Membership::query()->where('ulid', $owner->json('membership.ulid'))->firstOrFail();
        $this->assertSame(MembershipStatus::Active, $remaining->status);
        $this->assertTrue($remaining->hasRoleCode(PermissionCatalogue::OWNER));
        $this->assertTrue($remaining->hasOwnerAuthority());
        $this->assertTrue($remaining->is_owner);

        $this->postJson('/api/memberships/'.$owner->json('membership.ulid').'/deactivate')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FINAL_OWNER_REQUIRED');
    }

    public function test_stale_is_owner_flag_is_not_an_authorization_bypass(): void
    {
        $owner = $this->signInOwner('rbac-stale')->assertOk();
        $tenant = Tenant::query()->where('name', 'Mart rbac-stale')->firstOrFail();
        $other = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => 'EAST',
            'name' => 'East',
            'status' => BranchStatus::Active,
            'is_default' => false,
        ]);
        Warehouse::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $other->id,
            'code' => 'EAST',
            'name' => 'East Warehouse',
            'status' => WarehouseStatus::Active,
            'is_default' => true,
        ]);

        $ownerRole = $this->roleUlid(PermissionCatalogue::OWNER);
        $this->createRestrictedMember('rbac-stale', PermissionCatalogue::CASHIER, $owner->json('branch.ulid'));
        $this->createRestrictedMember('rbac-stale-admin', PermissionCatalogue::ADMIN, $owner->json('branch.ulid'));

        $cashier = $this->membershipByEmail('cashier-rbac-stale@example.com', $tenant->id);
        $admin = $this->membershipByEmail('cashier-rbac-stale-admin@example.com', $tenant->id);
        $cashier->forceFill(['is_owner' => true])->save();
        $admin->forceFill(['is_owner' => true])->save();

        $this->assertFalse($cashier->hasRoleCode(PermissionCatalogue::OWNER));
        $this->assertFalse($admin->hasRoleCode(PermissionCatalogue::OWNER));
        $this->assertFalse($cashier->hasOwnerAuthority());
        $this->assertFalse($admin->hasOwnerAuthority());

        $owners = app(FinalOwnerGuard::class)->lockActiveOwners($tenant->id);
        $this->assertCount(1, $owners);
        $this->assertSame($owner->json('membership.ulid'), $owners->first()->ulid);

        $this->postJson('/api/auth/logout')->assertOk();
        $cashierSession = $this->loginAs('rbac-stale', 'cashier-rbac-stale')->assertOk();

        $this->assertNotContains('owner', $cashierSession->json('roles.*.code'));
        $this->assertFalse($cashierSession->json('membership.is_owner'));
        $this->assertNotContains('users.manage_roles', $cashierSession->json('permissions'));
        $this->assertNotContains('users.manage_branches', $cashierSession->json('permissions'));
        $this->assertNotContains('roles.manage_permissions', $cashierSession->json('permissions'));

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertFalse($me->json('membership.is_owner'));
        $this->assertNotContains('users.manage_roles', $me->json('permissions'));
        $this->assertNotContains('owner', $me->json('roles.*.code'));

        $this->postJson('/api/branches/'.$other->ulid.'/switch')
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('rbac-stale', 'cashier-rbac-stale-admin')->assertOk();

        $this->putJson('/api/memberships/'.$admin->ulid.'/roles', [
            'roles' => [$ownerRole],
        ])->assertUnprocessable()->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    public function test_owner_role_without_is_owner_flag_still_protects_final_owner(): void
    {
        $owner = $this->signInOwner('rbac-flag-off')->assertOk();
        $membership = Membership::query()->where('ulid', $owner->json('membership.ulid'))->firstOrFail();
        $membership->forceFill(['is_owner' => false])->save();

        $this->assertTrue($membership->fresh()->hasRoleCode(PermissionCatalogue::OWNER));
        $this->assertTrue($membership->fresh()->hasOwnerAuthority());
        $this->assertFalse($membership->fresh()->is_owner);

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertTrue($me->json('membership.is_owner'));
        $this->assertContains('owner', $me->json('roles.*.code'));
        $this->assertContains('users.manage_roles', $me->json('permissions'));
        $this->assertContains('roles.manage_permissions', $me->json('permissions'));
        $this->assertContains('users.manage_branches', $me->json('permissions'));

        $this->putJson('/api/memberships/'.$membership->ulid.'/roles', [
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
        ])->assertForbidden()->assertJsonPath('error.key', 'FINAL_OWNER_REQUIRED');

        $this->postJson('/api/memberships/'.$membership->ulid.'/deactivate')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FINAL_OWNER_REQUIRED');
    }

    public function test_has_role_code_does_not_match_another_tenants_owner_role(): void
    {
        $tenantA = $this->signInOwner('rbac-code-a')->assertOk();
        $this->createRestrictedMember('rbac-code-a', PermissionCatalogue::CASHIER, $tenantA->json('branch.ulid'));
        $this->postJson('/api/auth/logout')->assertOk();

        $tenantB = $this->signInOwner('rbac-code-b')->assertOk();

        $tenantAModel = Tenant::query()->where('name', 'Mart rbac-code-a')->firstOrFail();
        $tenantBModel = Tenant::query()->where('name', 'Mart rbac-code-b')->firstOrFail();
        $ownerB = Role::query()
            ->where('tenant_id', $tenantBModel->id)
            ->where('code', PermissionCatalogue::OWNER)
            ->firstOrFail();

        $cashierA = $this->membershipByEmail('cashier-rbac-code-a@example.com', $tenantAModel->id);
        $cashierA->forceFill(['is_owner' => true])->save();
        $cashierA->roles()->attach($ownerB->id, [
            'ulid' => (string) Str::ulid(),
        ]);
        $cashierA->unsetRelation('roles');
        $cashierA->load('roles');

        $this->assertFalse($cashierA->hasRoleCode(PermissionCatalogue::OWNER));
        $this->assertFalse($cashierA->hasRoleCode(PermissionCatalogue::OWNER, $tenantAModel->id));
        $this->assertFalse($cashierA->hasRoleCode(PermissionCatalogue::OWNER, $tenantBModel->id));
        $this->assertFalse($cashierA->hasOwnerAuthority($tenantAModel->id));
        $this->assertFalse($cashierA->hasOwnerAuthority($tenantBModel->id));

        $ownerA = Membership::query()->where('ulid', $tenantA->json('membership.ulid'))->firstOrFail();
        $this->assertTrue($ownerA->hasRoleCode(PermissionCatalogue::OWNER, $tenantAModel->id));
        $this->assertFalse($ownerA->hasRoleCode(PermissionCatalogue::OWNER, $tenantBModel->id));
        $this->assertFalse($ownerA->hasOwnerAuthority($tenantBModel->id));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('rbac-code-a', 'cashier-rbac-code-a')->assertOk();

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertSame($tenantA->json('tenant.ulid'), $me->json('tenant.ulid'));
        $this->assertFalse($me->json('membership.is_owner'));
        $this->assertNotContains('owner', $me->json('roles.*.code'));
        $this->assertNotContains('users.manage_roles', $me->json('permissions'));
    }

    public function test_owner_permissions_cannot_be_replaced(): void
    {
        $this->signInOwner('rbac-owner-perms')->assertOk();

        $this->putJson('/api/roles/'.$this->roleUlid('owner').'/permissions', [
            'permissions' => ['dashboard.view'],
        ])->assertForbidden()->assertJsonPath('error.key', 'SYSTEM_ROLE_PROTECTED');
    }

    private function roleUlid(string $code): string
    {
        $roles = $this->getJson('/api/roles')->assertOk()->json();

        foreach ($roles as $role) {
            if ($role['code'] === $code) {
                return $role['ulid'];
            }
        }

        $this->fail('Missing role '.$code);
    }

    private function createRestrictedMember(string $suffix, string $roleCode, string $branchUlid): void
    {
        $roleUlid = $this->roleUlid($roleCode);

        $this->postJson('/api/memberships', [
            'name' => 'Cashier '.$suffix,
            'username' => $this->staffUsername($suffix),
            'recovery_email' => "cashier-{$suffix}@example.com",
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$roleUlid],
            'branches' => [$branchUlid],
        ])->assertCreated();
    }

    private function membershipByEmail(string $email, int $tenantId): Membership
    {
        $user = User::query()->where('email', $email)->firstOrFail();

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}
