<?php

namespace Tests\Feature;

use App\Authz\PermissionCatalogue;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WarehouseAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_edit_set_default_and_deactivate_non_current_warehouse(): void
    {
        $session = $this->signInOwner('warehouse-admin')->assertOk();
        $branchUlid = (string) $session->json('branch.ulid');
        $currentUlid = (string) $session->json('warehouse.ulid');

        $north = $this->postJson('/api/warehouses', [
            'code' => 'north',
            'name' => 'North Warehouse',
            'branch_ulid' => $branchUlid,
        ])->assertCreated()
            ->assertJsonPath('code', 'NORTH')
            ->assertJsonPath('name', 'North Warehouse')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_default', false)
            ->assertJsonPath('branch_ulid', $branchUlid);

        $northUlid = (string) $north->json('ulid');
        $this->assertNoInternalIds($north->json());

        $south = $this->postJson('/api/warehouses', [
            'code' => 'SOUTH',
            'name' => 'South Warehouse',
            'branch_ulid' => $branchUlid,
        ])->assertCreated();
        $southUlid = (string) $south->json('ulid');

        $updated = $this->patchJson('/api/warehouses/'.$northUlid, [
            'code' => 'north-2',
            'name' => 'North Warehouse Updated',
            'is_default' => true,
        ])->assertOk()
            ->assertJsonPath('code', 'NORTH-2')
            ->assertJsonPath('name', 'North Warehouse Updated')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_default', true)
            ->assertJsonPath('branch_ulid', $branchUlid);

        $this->assertNoInternalIds($updated->json());
        $this->assertFalse((bool) Warehouse::query()->where('ulid', $currentUlid)->value('is_default'));

        $this->patchJson('/api/warehouses/'.$southUlid, [
            'is_default' => true,
        ])->assertOk()->assertJsonPath('is_default', true);

        $this->assertFalse((bool) Warehouse::query()->where('ulid', $northUlid)->value('is_default'));

        $this->patchJson('/api/warehouses/'.$northUlid, [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('is_default', false);

        $this->patchJson('/api/warehouses/'.$northUlid, [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('status', 'active');

        $this->patchJson('/api/warehouses/'.$northUlid, [
            'code' => 'SOUTH',
        ])->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['code']]]);

        $this->patchJson('/api/warehouses/'.$currentUlid, [
            'status' => 'inactive',
        ])->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['status']]]);

        $this->patchJson('/api/warehouses/'.$southUlid, [
            'status' => 'inactive',
        ])->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['status']]]);

        $this->patchJson('/api/warehouses/'.$southUlid, [
            'is_default' => false,
        ])->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['is_default']]]);

        $warehouses = $this->getJson('/api/warehouses')->assertOk();
        $this->assertNoInternalIds($warehouses->json());
    }

    public function test_warehouse_updates_are_tenant_and_active_branch_scoped(): void
    {
        $tenantA = $this->signInOwner('warehouse-tenant-a')->assertOk();
        $branchA = (string) $tenantA->json('branch.ulid');

        $foreign = $this->postJson('/api/warehouses', [
            'code' => 'PRIVATE',
            'name' => 'Private Warehouse',
            'branch_ulid' => $branchA,
        ])->assertCreated();
        $foreignUlid = (string) $foreign->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('warehouse-tenant-b')->assertOk();

        $this->patchJson('/api/warehouses/'.$foreignUlid, [
            'name' => 'Leaked Rename',
        ])->assertNotFound();

        $this->assertSame(
            'Private Warehouse',
            Warehouse::query()->where('ulid', $foreignUlid)->value('name'),
        );
    }

    public function test_cashier_can_list_but_cannot_administer_warehouses(): void
    {
        $owner = $this->signInOwner('warehouse-perm')->assertOk();
        $branchUlid = (string) $owner->json('branch.ulid');
        $warehouseUlid = (string) $owner->json('warehouse.ulid');

        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $cashier = collect($roles)->firstWhere('code', PermissionCatalogue::CASHIER);

        $this->postJson('/api/memberships', [
            'name' => 'Warehouse Cashier',
            'username' => 'warehouse-cashier',
            'recovery_email' => 'warehouse-cashier@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashier['ulid']],
            'branches' => [$branchUlid],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('warehouse-perm', 'warehouse-cashier')->assertOk();

        $this->getJson('/api/warehouses')->assertOk();

        $this->postJson('/api/warehouses', [
            'code' => 'DENIED',
            'name' => 'Denied Warehouse',
            'branch_ulid' => $branchUlid,
        ])->assertForbidden();

        $this->patchJson('/api/warehouses/'.$warehouseUlid, [
            'name' => 'Denied Rename',
        ])->assertForbidden();
    }
}
