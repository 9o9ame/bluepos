<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Authz\PermissionService;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleHold;
use App\Models\SaleHoldItem;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleHoldTest extends TestCase
{
    use DatabaseTransactions;

    public function test_hold_persists_without_posting_sale_or_touching_stock(): void
    {
        $this->signInOwner('hold-1')->assertOk();

        $productUlid = $this->createProduct('Held Cola', '125.0000');
        $this->giveStock($productUlid, '10.000000');

        $before = $this->stock($productUlid);

        $hold = $this->postJson('/api/sales/holds', [
            'sale_date' => '2026-10-05',
            'price_type' => 'retail',
            'notes' => 'Counter hold',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '2.000000',
                'discount_percent' => '5.00000000',
                'discount_amount' => '0',
            ]],
        ], $this->idem('hold-1-create'))
            ->assertCreated()
            ->assertJsonPath('price_type', 'retail')
            ->assertJsonPath('sale_line_count', 1)
            ->assertJsonPath('lines.0.product_ulid', $productUlid)
            ->assertJsonPath('lines.0.quantity', '2.000000')
            ->assertJsonPath('lines.0.retail_price', '125.0000')
            ->assertJsonPath('lines.0.available_base_stock', '10.000000');

        $this->assertNoInternalIds($hold->json());
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame($before, $this->stock($productUlid));

        $holdUlid = (string) $hold->json('ulid');

        $this->getJson('/api/sales/holds')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.ulid', $holdUlid);

        $this->getJson('/api/sales/holds/'.$holdUlid)
            ->assertOk()
            ->assertJsonPath('lines.0.product_name', 'Held Cola');
    }

    public function test_hold_persists_active_salesman_for_recall(): void
    {
        $this->signInOwner('hold-salesman-active')->assertOk();

        $salesman = $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-HOLD',
            'name' => 'Hold Salesman',
        ])->assertCreated();

        $salesmanUlid = (string) $salesman->json('identity_ulid');
        $productUlid = $this->createProduct('Hold Salesman Item', '75.0000');

        $hold = $this->postJson('/api/sales/holds', [
            'salesman_ulid' => $salesmanUlid,
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-salesman-active-1'))
            ->assertCreated()
            ->assertJsonPath('salesman.ulid', $salesmanUlid)
            ->assertJsonPath('salesman.name', 'Hold Salesman');

        $this->getJson('/api/sales/holds/'.$hold->json('ulid'))
            ->assertOk()
            ->assertJsonPath('salesman.ulid', $salesmanUlid)
            ->assertJsonPath('salesman.name', 'Hold Salesman');
    }

    public function test_hold_rejects_inactive_salesman(): void
    {
        $this->signInOwner('hold-salesman-inactive')->assertOk();

        $salesman = $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-HOLD-INACTIVE',
            'name' => 'Inactive Hold Salesman',
        ])->assertCreated();

        $salesmanUlid = (string) $salesman->json('identity_ulid');

        $this->patchJson('/api/party-profiles/'.$salesmanUlid, [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-HOLD-INACTIVE',
            'name' => 'Inactive Hold Salesman',
            'is_active' => false,
        ])->assertOk();

        $productUlid = $this->createProduct('Inactive Hold Salesman Item', '75.0000');

        $this->postJson('/api/sales/holds', [
            'salesman_ulid' => $salesmanUlid,
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-salesman-inactive-1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, SaleHold::query()->count());
    }

    public function test_replayed_hold_key_does_not_create_a_duplicate(): void
    {
        $this->signInOwner('hold-2')->assertOk();
        $productUlid = $this->createProduct('Replay Hold Item', '50.0000');

        $payload = [
            'price_type' => 'default',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ];

        $first = $this->postJson('/api/sales/holds', $payload, $this->idem('hold-2-replay'))
            ->assertCreated();

        $second = $this->postJson('/api/sales/holds', $payload, $this->idem('hold-2-replay'))
            ->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(1, SaleHold::query()->count());
        $this->assertSame(1, SaleHoldItem::query()->count());
    }

    public function test_hold_rejects_product_from_another_tenant(): void
    {
        $this->signInOwner('hold-3a')->assertOk();
        $foreignProduct = $this->createProduct('Foreign Hold Item', '20.0000');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('hold-3b')->assertOk();

        $this->postJson('/api/sales/holds', [
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $foreignProduct,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-3-foreign'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, SaleHold::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->count());
    }

    public function test_holds_are_scoped_to_active_branch_and_warehouse(): void
    {
        $this->signInOwner('hold-4')->assertOk();
        $context = app(TenantContext::class);

        $otherBranch = Branch::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'HOLD-B2',
            'name' => 'Hold Branch 2',
            'status' => 'active',
            'is_default' => false,
        ]);

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'code' => 'HOLD-W2',
            'name' => 'Hold Warehouse 2',
            'status' => 'active',
            'is_default' => true,
        ]);

        $hold = SaleHold::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'warehouse_id' => $otherWarehouse->id,
            'customer_id' => null,
            'salesman_party_profile_id' => null,
            'sale_date' => '2026-10-05',
            'price_type' => 'retail',
            'notes' => null,
            'idempotency_key' => 'hold-4-other',
            'created_by' => $context->userId(),
            'updated_by' => $context->userId(),
        ]);

        $this->getJson('/api/sales/holds')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->getJson('/api/sales/holds/'.$hold->ulid)
            ->assertNotFound();

        $this->deleteJson('/api/sales/holds/'.$hold->ulid)
            ->assertNotFound();
    }

    public function test_discard_deletes_only_the_draft_hold_and_its_items(): void
    {
        $this->signInOwner('hold-5')->assertOk();
        $productUlid = $this->createProduct('Discard Hold Item', '75.0000');

        $hold = $this->postJson('/api/sales/holds', [
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-5-create'))->assertCreated();

        $holdUlid = (string) $hold->json('ulid');

        $this->deleteJson('/api/sales/holds/'.$holdUlid)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, SaleHold::query()->count());
        $this->assertSame(0, SaleHoldItem::query()->count());
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_sales_hold_permission_can_create_and_discard_but_not_recall(): void
    {
        $this->signInOwner('hold-permission-hold')->assertOk();
        $productUlid = $this->createProduct('Hold Permission Item', '25.0000');

        $this->replaceCurrentPermissions(['sales.hold']);

        $hold = $this->postJson('/api/sales/holds', [
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-permission-hold-create'))
            ->assertCreated();

        $holdUlid = (string) $hold->json('ulid');

        $this->getJson('/api/sales/holds')->assertForbidden();
        $this->getJson('/api/sales/holds/'.$holdUlid)->assertForbidden();

        $this->deleteJson('/api/sales/holds/'.$holdUlid)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_sales_recall_permission_can_list_and_load_but_not_create_or_discard(): void
    {
        $this->signInOwner('hold-permission-recall')->assertOk();
        $productUlid = $this->createProduct('Recall Permission Item', '30.0000');

        $hold = $this->postJson('/api/sales/holds', [
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-permission-recall-seed'))
            ->assertCreated();

        $holdUlid = (string) $hold->json('ulid');

        $this->replaceCurrentPermissions(['sales.recall']);

        $this->getJson('/api/sales/holds')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.ulid', $holdUlid);

        $this->getJson('/api/sales/holds/'.$holdUlid)
            ->assertOk()
            ->assertJsonPath('ulid', $holdUlid);

        $this->postJson('/api/sales/holds', [
            'price_type' => 'retail',
            'lines' => [[
                'product_ulid' => $productUlid,
                'line_kind' => 'sale',
                'quantity' => '1.000000',
            ]],
        ], $this->idem('hold-permission-recall-create'))
            ->assertForbidden();

        $this->deleteJson('/api/sales/holds/'.$holdUlid)
            ->assertForbidden();

        $this->assertSame(1, SaleHold::query()->count());
    }

    /**
     * Replace the current membership role with a custom role containing only
     * the requested permissions. Pivot ULIDs are populated explicitly because
     * BluePOS public identity rules also apply to RBAC pivot rows.
     *
     * @param list<string> $keys
     */
    private function replaceCurrentPermissions(array $keys): void
    {
        $context = app(TenantContext::class);
        $membership = $context->membership();

        $role = Role::query()->create([
            'tenant_id' => $context->tenantId(),
            'name' => 'Hold Test '.Str::random(8),
            'code' => 'hold_test_'.Str::lower(Str::random(10)),
            'description' => 'Focused Sale Hold RBAC test role',
            'branch_access' => 'all_branches',
            'is_system' => false,
            'is_active' => true,
        ]);

        $permissions = Permission::query()
            ->whereIn('key', $keys)
            ->get();

        $this->assertCount(count($keys), $permissions);

        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert([
                'ulid' => (string) Str::ulid(),
                'role_id' => $role->id,
                'permission_id' => $permission->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('membership_roles')
            ->where('membership_id', $membership->id)
            ->delete();

        DB::table('membership_roles')->insert([
            'ulid' => (string) Str::ulid(),
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->forgetInstance(PermissionService::class);
    }

    private function createProduct(string $name, string $retail): string
    {
        return $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retail,
            ]],
        ])->assertCreated()->json('ulid');
    }

    private function giveStock(string $productUlid, string $quantity): void
    {
        $context = app(TenantContext::class);
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        StockBalance::query()->updateOrCreate(
            [
                'tenant_id' => $context->tenantId(),
                'branch_id' => $context->branchId(),
                'warehouse_id' => $context->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'quantity' => $quantity,
                'average_cost' => '10.0000',
                'stock_value' => bcmul($quantity, '10.0000', 4),
            ],
        );
    }

    private function stock(string $productUlid): string
    {
        $context = app(TenantContext::class);
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        return (string) StockBalance::query()
            ->where('tenant_id', $context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    /** @return array<string, string> */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
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
}
