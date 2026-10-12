<?php

namespace Tests\Feature;

use App\Authz\PermissionCatalogue;
use App\Models\Branch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BranchAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_edit_set_default_and_deactivate_non_current_branch(): void
    {
        $session = $this->signInOwner('branch-admin')->assertOk();
        $mainUlid = (string) $session->json('branch.ulid');

        $north = $this->postJson('/api/branches', [
            'code' => 'north',
            'name' => 'North Branch',
        ])->assertCreated()
            ->assertJsonPath('code', 'NORTH')
            ->assertJsonPath('name', 'North Branch')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_default', false);

        $northUlid = (string) $north->json('ulid');
        $this->assertNoInternalIds($north->json());

        $south = $this->postJson('/api/branches', [
            'code' => 'SOUTH',
            'name' => 'South Branch',
        ])->assertCreated();
        $southUlid = (string) $south->json('ulid');

        $updated = $this->patchJson('/api/branches/'.$northUlid, [
            'code' => 'north-2',
            'name' => 'North Branch Updated',
            'is_default' => true,
        ])->assertOk()
            ->assertJsonPath('code', 'NORTH-2')
            ->assertJsonPath('name', 'North Branch Updated')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_default', true);

        $this->assertNoInternalIds($updated->json());
        $this->assertFalse((bool) Branch::query()->where('ulid', $mainUlid)->value('is_default'));

        $this->patchJson('/api/branches/'.$southUlid, [
            'is_default' => true,
        ])->assertOk()->assertJsonPath('is_default', true);

        $this->assertFalse((bool) Branch::query()->where('ulid', $northUlid)->value('is_default'));

        $this->patchJson('/api/branches/'.$northUlid, [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('is_default', false);

        $this->patchJson('/api/branches/'.$northUlid, [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('status', 'active');

        $this->patchJson('/api/branches/'.$northUlid, [
            'code' => 'SOUTH',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->patchJson('/api/branches/'.$mainUlid, [
            'status' => 'inactive',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson('/api/branches/'.$southUlid, [
            'status' => 'inactive',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson('/api/branches/'.$southUlid, [
            'is_default' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_default']);

        $branches = $this->getJson('/api/branches')->assertOk();
        $this->assertNoInternalIds($branches->json());
    }

    public function test_branch_updates_are_tenant_scoped(): void
    {
        $this->signInOwner('branch-tenant-a')->assertOk();

        $foreign = $this->postJson('/api/branches', [
            'code' => 'PRIVATE',
            'name' => 'Private Branch',
        ])->assertCreated();
        $foreignUlid = (string) $foreign->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('branch-tenant-b')->assertOk();

        $this->patchJson('/api/branches/'.$foreignUlid, [
            'name' => 'Leaked Rename',
        ])->assertNotFound();

        $this->assertSame(
            'Private Branch',
            Branch::query()->where('ulid', $foreignUlid)->value('name'),
        );
    }

    public function test_cashier_can_list_but_cannot_administer_branches(): void
    {
        $owner = $this->signInOwner('branch-perm')->assertOk();
        $branchUlid = (string) $owner->json('branch.ulid');

        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $cashier = collect($roles)->firstWhere('code', PermissionCatalogue::CASHIER);

        $this->postJson('/api/memberships', [
            'name' => 'Branch Cashier',
            'username' => 'branch-cashier',
            'recovery_email' => 'branch-cashier@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashier['ulid']],
            'branches' => [$branchUlid],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('branch-perm', 'branch-cashier')->assertOk();

        $this->getJson('/api/branches')->assertOk();

        $this->postJson('/api/branches', [
            'code' => 'DENIED',
            'name' => 'Denied Branch',
        ])->assertForbidden();

        $this->patchJson('/api/branches/'.$branchUlid, [
            'name' => 'Denied Rename',
        ])->assertForbidden();
    }
}
