<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductLedgerReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_product_ledger_returns_opening_running_and_period_totals_across_pages(): void
    {
        $session = $this->signInOwner('product-ledger')->assertOk();
        $context = app(TenantContext::class);
        $productUlid = $this->createProduct('Ledger Product');
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        $this->movement($product, '2026-09-30 12:00:00', '5.000000', '10.0000', StockMovementType::OpeningBalance, 'opening');
        $this->movement($product, '2026-10-01 09:00:00', '2.000000', '11.0000', StockMovementType::Purchase, 'purchase');
        $this->movement($product, '2026-10-02 10:00:00', '-1.000000', '11.0000', StockMovementType::Sale, 'sale');
        $this->movement($product, '2026-10-03 11:00:00', '4.000000', '12.0000', StockMovementType::AdjustmentIn, 'inventory_adjustment');

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'code' => 'LEDGER-OTHER',
            'name' => 'Ledger Other Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $product->id,
            'movement_type' => StockMovementType::Purchase,
            'quantity' => '100.000000',
            'unit_cost' => '99.0000',
            'total_cost' => '9900.0000',
            'reference_type' => 'purchase',
            'reference_ulid' => '01OTHERWAREHOUSE0000000000',
            'occurred_at' => '2026-10-02 12:00:00',
            'posted_by' => $context->userId(),
        ]);

        $pageOne = $this->getJson(
            '/api/reports/products/ledger?product_ulid='.$productUlid
            .'&from_date=2026-10-01&to_date=2026-10-31&per_page=2&page=1'
        )->assertOk()
            ->assertJsonPath('warehouse.ulid', $session->json('warehouse.ulid'))
            ->assertJsonPath('opening_quantity', '5.000000')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.totals.quantity_in', '6.000000')
            ->assertJsonPath('meta.totals.quantity_out', '1.000000')
            ->assertJsonPath('meta.totals.net_quantity', '5.000000')
            ->assertJsonPath('meta.closing_quantity', '10.000000')
            ->assertJsonPath('data.0.quantity_in', '2.000000')
            ->assertJsonPath('data.0.running_quantity', '7.000000')
            ->assertJsonPath('data.1.quantity_out', '1.000000')
            ->assertJsonPath('data.1.running_quantity', '6.000000');

        $this->assertSame('11.0000', $pageOne->json('data.0.unit_cost'));
        $this->assertSame('22.0000', $pageOne->json('data.0.total_cost'));
        $this->assertNoInternalIds($pageOne->json());

        $pageTwo = $this->getJson(
            '/api/reports/products/ledger?product_ulid='.$productUlid
            .'&from_date=2026-10-01&to_date=2026-10-31&per_page=2&page=2'
        )->assertOk()
            ->assertJsonPath('data.0.quantity_in', '4.000000')
            ->assertJsonPath('data.0.running_quantity', '10.000000')
            ->assertJsonPath('meta.current_page', 2);

        $this->assertCount(1, $pageTwo->json('data'));
        $this->assertNoInternalIds($pageTwo->json());
    }

    public function test_product_ledger_hides_cost_without_inventory_permission_and_validates_dates(): void
    {
        $owner = $this->signInOwner('product-ledger-cost')->assertOk();
        $productUlid = $this->createProduct('Ledger Cost Protected');
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        $this->movement($product, '2026-10-01 09:00:00', '2.000000', '123.4500', StockMovementType::Purchase, 'purchase');

        $role = $this->postJson('/api/roles', [
            'name' => 'Ledger Report Only',
            'code' => 'ledger_report_only',
        ])->assertCreated();

        $this->putJson('/api/roles/'.$role->json('ulid').'/permissions', [
            'permissions' => ['reports.inventory'],
        ])->assertOk();

        $this->postJson('/api/memberships', [
            'name' => 'Ledger Reporter',
            'username' => 'ledger-reporter',
            'recovery_email' => 'ledger-reporter@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$role->json('ulid')],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('product-ledger-cost', 'ledger-reporter')->assertOk();

        $this->getJson(
            '/api/reports/products/ledger?product_ulid='.$productUlid
            .'&from_date=2026-10-01&to_date=2026-10-31'
        )->assertOk()
            ->assertJsonPath('data.0.unit_cost', null)
            ->assertJsonPath('data.0.total_cost', null)
            ->assertJsonPath('data.0.running_quantity', '2.000000');

        $this->getJson(
            '/api/reports/products/ledger?product_ulid='.$productUlid
            .'&from_date=2026-10-31&to_date=2026-10-01'
        )->assertUnprocessable();
    }

    public function test_product_ledger_requires_permission_and_does_not_leak_foreign_product(): void
    {
        $owner = $this->signInOwner('product-ledger-a')->assertOk();
        $productUlid = $this->createProduct('Foreign Ledger Product');
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $this->movement($product, '2026-10-01 09:00:00', '3.000000', '5.0000', StockMovementType::Purchase, 'purchase');

        $cashierRole = collect($this->getJson('/api/roles')->assertOk()->json())
            ->firstWhere('code', 'cashier');

        $this->postJson('/api/memberships', [
            'name' => 'Ledger No Permission',
            'username' => 'ledger-no-permission',
            'recovery_email' => 'ledger-no-permission@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashierRole['ulid']],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('product-ledger-a', 'ledger-no-permission')->assertOk();

        $this->getJson(
            '/api/reports/products/ledger?product_ulid='.$productUlid
            .'&from_date=2026-10-01&to_date=2026-10-31'
        )->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('product-ledger-b')->assertOk();

        $this->getJson(
            '/api/reports/products/ledger?product_ulid='.$productUlid
            .'&from_date=2026-10-01&to_date=2026-10-31'
        )->assertNotFound();
    }

    private function createProduct(string $name): string
    {
        return (string) $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');
    }

    private function movement(
        Product $product,
        string $occurredAt,
        string $quantity,
        string $unitCost,
        StockMovementType $type,
        string $referenceType,
    ): void {
        $context = app(TenantContext::class);

        StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => bcmul($quantity, $unitCost, 4),
            'reference_type' => $referenceType,
            'reference_ulid' => '01'.str_pad((string) StockMovement::query()->count(), 24, '0', STR_PAD_LEFT),
            'occurred_at' => $occurredAt,
            'posted_by' => $context->userId(),
            'notes' => 'Report movement',
        ]);
    }

    private function unitUlid(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
