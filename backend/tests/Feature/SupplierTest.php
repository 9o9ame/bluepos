<?php

namespace Tests\Feature;

use App\Authz\PermissionCatalogue;
use App\Models\AuditLog;
use App\Models\ProductSupplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_create_update_deactivate_and_reactivate(): void
    {
        $this->signInOwner('sup-crud')->assertOk();

        $created = $this->postJson('/api/suppliers', [
            'code' => 'sup-01',
            'name' => 'Alpha Supplies',
            'contact_person' => 'Ali',
            'phone' => '0300-1111111',
            'email' => 'alpha@example.com',
            'address' => 'Karachi',
            'tax_number' => 'NTN-1',
            'notes' => 'Preferred',
        ])->assertCreated();

        $created->assertJsonPath('code', 'SUP-01');
        $created->assertJsonPath('name', 'Alpha Supplies');
        $created->assertJsonPath('is_active', true);
        $this->assertNoInternalIds($created->json());
        $ulid = $created->json('ulid');

        $this->assertTrue(AuditLog::query()->where('event', 'SUPPLIER_CREATED')->where('resource_ulid', $ulid)->exists());

        $updated = $this->patchJson('/api/suppliers/'.$ulid, [
            'name' => 'Alpha Supplies Ltd',
            'phone' => '0300-2222222',
        ])->assertOk();
        $updated->assertJsonPath('name', 'Alpha Supplies Ltd');
        $this->assertTrue(AuditLog::query()->where('event', 'SUPPLIER_UPDATED')->where('resource_ulid', $ulid)->exists());

        $this->deleteJson('/api/suppliers/'.$ulid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/suppliers/'.$ulid)->assertOk()->assertJsonPath('is_active', false);
        $this->assertTrue(AuditLog::query()->where('event', 'SUPPLIER_DEACTIVATED')->where('resource_ulid', $ulid)->exists());

        $reactivated = $this->patchJson('/api/suppliers/'.$ulid, [
            'is_active' => true,
        ])->assertOk();
        $reactivated->assertJsonPath('is_active', true);
        $this->assertTrue(AuditLog::query()->where('event', 'SUPPLIER_ACTIVATED')->where('resource_ulid', $ulid)->exists());

        $list = $this->getJson('/api/suppliers')->assertOk()->json();
        $this->assertContains($ulid, collect($list)->pluck('ulid')->all());
    }

    public function test_supplier_code_unique_per_tenant_and_tenant_isolation(): void
    {
        $this->signInOwner('sup-iso-a')->assertOk();
        $ulidA = $this->postJson('/api/suppliers', [
            'code' => 'SHARED',
            'name' => 'Tenant A Supplier',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/suppliers', [
            'code' => 'SHARED',
            'name' => 'Duplicate Same Tenant',
        ])->assertStatus(422);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sup-iso-b')->assertOk();

        $this->postJson('/api/suppliers', [
            'code' => 'SHARED',
            'name' => 'Tenant B Supplier',
        ])->assertCreated();

        $list = $this->getJson('/api/suppliers')->assertOk()->json();
        $this->assertNotContains($ulidA, collect($list)->pluck('ulid')->all());
        $this->getJson('/api/suppliers/'.$ulidA)->assertNotFound();
        $this->patchJson('/api/suppliers/'.$ulidA, ['name' => 'Leaked'])->assertNotFound();
        $this->deleteJson('/api/suppliers/'.$ulidA)->assertNotFound();
    }

    public function test_product_primary_supplier_assignment_change_clear_and_inactive_readable(): void
    {
        $this->signInOwner('sup-product')->assertOk();
        $pcs = $this->unitUlid('PCS');

        $supplierA = $this->postJson('/api/suppliers', [
            'code' => 'SUPA',
            'name' => 'Supplier A',
        ])->assertCreated()->json('ulid');
        $supplierB = $this->postJson('/api/suppliers', [
            'code' => 'SUPB',
            'name' => 'Supplier B',
        ])->assertCreated()->json('ulid');

        $product = $this->postJson('/api/products', [
            'name' => 'Supplier Linked Product',
            'base_unit_ulid' => $pcs,
            'primary_supplier_ulid' => $supplierA,
            'supplier_product_code' => 'VENDOR-A-1',
        ])->assertCreated();

        $productUlid = $product->json('ulid');
        $product->assertJsonPath('primary_supplier.ulid', $supplierA);
        $product->assertJsonPath('primary_supplier.code', 'SUPA');
        $product->assertJsonPath('supplier_product_code', 'VENDOR-A-1');
        $this->assertNoInternalIds($product->json());

        $this->patchJson('/api/products/'.$productUlid, [
            'primary_supplier_ulid' => $supplierB,
            'supplier_product_code' => 'VENDOR-B-9',
        ])->assertOk()
            ->assertJsonPath('primary_supplier.ulid', $supplierB)
            ->assertJsonPath('supplier_product_code', 'VENDOR-B-9');

        $this->assertSame(1, ProductSupplier::query()->where('is_primary', true)->count());
        $this->assertSame(2, ProductSupplier::query()->count());

        $this->patchJson('/api/products/'.$productUlid, [
            'primary_supplier_ulid' => null,
            'supplier_product_code' => null,
        ])->assertOk()
            ->assertJsonPath('primary_supplier', null)
            ->assertJsonPath('supplier_product_code', null);

        $this->assertSame(0, ProductSupplier::query()->where('is_primary', true)->count());
        $this->assertSame(2, ProductSupplier::query()->count());

        $this->patchJson('/api/products/'.$productUlid, [
            'primary_supplier_ulid' => $supplierA,
            'supplier_product_code' => 'VENDOR-A-RESTORE',
        ])->assertOk();

        $this->deleteJson('/api/suppliers/'.$supplierA)->assertOk();
        $existing = $this->getJson('/api/products/'.$productUlid)->assertOk();
        $existing->assertJsonPath('primary_supplier.ulid', $supplierA);
        $existing->assertJsonPath('primary_supplier.is_active', false);
        $existing->assertJsonPath('supplier_product_code', 'VENDOR-A-RESTORE');
        $this->assertNoInternalIds($existing->json());
    }

    public function test_foreign_supplier_assignment_and_unauthorized_mutations_are_blocked(): void
    {
        $owner = $this->signInOwner('sup-auth-a')->assertOk();
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'PRIVATE',
            'name' => 'Private Supplier',
        ])->assertCreated()->json('ulid');
        $this->createCashier('sup-auth-a', $owner->json('branch.ulid'));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sup-auth-b')->assertOk();
        $ownProduct = $this->postJson('/api/products', [
            'name' => 'Own Product',
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated();

        $this->postJson('/api/products', [
            'name' => 'Foreign Supplier Product',
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'primary_supplier_ulid' => $supplierUlid,
        ])->assertNotFound();
        $this->patchJson('/api/products/'.$ownProduct->json('ulid'), [
            'primary_supplier_ulid' => $supplierUlid,
        ])->assertNotFound();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('sup-auth-a', 'cashier-sup-auth-a')->assertOk();

        $this->postJson('/api/suppliers', [
            'code' => 'NOPE',
            'name' => 'Nope',
        ])->assertForbidden();
        $this->patchJson('/api/suppliers/'.$supplierUlid, ['name' => 'Forbidden'])->assertForbidden();
        $this->deleteJson('/api/suppliers/'.$supplierUlid)->assertForbidden();
    }

    private function unitUlid(string $code): string
    {
        $units = $this->getJson('/api/units')->assertOk()->json();
        foreach ($units as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }

    private function createCashier(string $suffix, string $branchUlid): void
    {
        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $cashier = collect($roles)->firstWhere('code', PermissionCatalogue::CASHIER);

        $this->postJson('/api/memberships', [
            'name' => 'Cashier '.$suffix,
            'username' => $this->staffUsername($suffix),
            'recovery_email' => "cashier-{$suffix}@example.com",
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashier['ulid']],
            'branches' => [$branchUlid],
        ])->assertCreated();
    }
}
