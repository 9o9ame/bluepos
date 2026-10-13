<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductStockStatusReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_status_uses_active_warehouse_filters_and_server_totals(): void
    {
        $owner = $this->signInOwner('stock-status')->assertOk();
        $context = app(TenantContext::class);

        $matchUlid = $this->createProduct('Status Match Product', '5.000000');
        $healthyUlid = $this->createProduct('Status Healthy Product', '1.000000');
        $zeroUlid = $this->createProduct('Status Zero Product', '2.000000');

        $match = Product::query()->where('ulid', $matchUlid)->firstOrFail();
        $healthy = Product::query()->where('ulid', $healthyUlid)->firstOrFail();

        $this->balance($match, $context->warehouseId(), '3.000000', '10.0000');
        $this->balance($healthy, $context->warehouseId(), '8.000000', '4.0000');

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'code' => 'OTHER',
            'name' => 'Other Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        $this->balance($match, $otherWarehouse->id, '999.000000', '99.0000');

        $this->putJson('/api/products/'.$matchUlid.'/prices', [
            'prices' => [[
                'price_type' => 'retail',
                'amount' => '20.0000',
                'is_active' => true,
            ]],
        ])->assertOk();

        $this->putJson('/api/products/'.$healthyUlid.'/prices', [
            'prices' => [[
                'price_type' => 'retail',
                'amount' => '8.0000',
                'is_active' => true,
            ]],
        ])->assertOk();

        $all = $this->getJson('/api/reports/products/stock-status?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.warehouse.ulid', $owner->json('warehouse.ulid'))
            ->assertJsonPath('meta.total', 3);

        $rows = collect($all->json('data'))->keyBy('product.ulid');

        $this->assertSame('3.000000', $rows[$matchUlid]['quantity']);
        $this->assertSame('10.0000', $rows[$matchUlid]['average_cost']);
        $this->assertSame('30.0000', $rows[$matchUlid]['stock_value']);
        $this->assertSame('20.0000', $rows[$matchUlid]['retail_rate']);
        $this->assertSame('60.0000', $rows[$matchUlid]['retail_stock_value']);
        $this->assertSame('0.000000', $rows[$zeroUlid]['quantity']);

        $this->assertSame('11.000000', bcadd((string) $all->json('meta.totals.quantity'), '0', 6));
        $this->assertSame('62.0000', bcadd((string) $all->json('meta.totals.stock_value'), '0', 4));
        $this->assertSame('124.0000', bcadd((string) $all->json('meta.totals.retail_stock_value'), '0', 4));
        $this->assertNoInternalIds($all->json());

        $withBalance = $this->getJson('/api/reports/products/stock-status?with_balance=1&per_page=100')
            ->assertOk();
        $withBalanceUlids = collect($withBalance->json('data'))->pluck('product.ulid');
        $this->assertTrue($withBalanceUlids->contains($matchUlid));
        $this->assertTrue($withBalanceUlids->contains($healthyUlid));
        $this->assertFalse($withBalanceUlids->contains($zeroUlid));

        $low = $this->getJson('/api/reports/products/stock-status?low_stock=1&per_page=100')
            ->assertOk();
        $lowUlids = collect($low->json('data'))->pluck('product.ulid');
        $this->assertTrue($lowUlids->contains($matchUlid));
        $this->assertTrue($lowUlids->contains($zeroUlid));
        $this->assertFalse($lowUlids->contains($healthyUlid));

        $search = $this->getJson('/api/reports/products/stock-status?q=Match&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->assertSame($matchUlid, $search->json('data.0.product.ulid'));
        $this->assertSame('3.000000', bcadd((string) $search->json('meta.totals.quantity'), '0', 6));
    }

    public function test_stock_status_hides_cost_without_inventory_permission(): void
    {
        $owner = $this->signInOwner('stock-status-cost')->assertOk();
        $productUlid = $this->createProduct('Report Cost Protected', '1.000000');
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $this->balance($product, app(TenantContext::class)->warehouseId(), '2.000000', '123.4500');

        $role = $this->postJson('/api/roles', [
            'name' => 'Inventory Report Only',
            'code' => 'inventory_report_only',
        ])->assertCreated();

        $this->putJson('/api/roles/'.$role->json('ulid').'/permissions', [
            'permissions' => ['reports.inventory'],
        ])->assertOk();

        $this->postJson('/api/memberships', [
            'name' => 'Inventory Reporter',
            'username' => 'inventory-reporter',
            'recovery_email' => 'inventory-reporter@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$role->json('ulid')],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('stock-status-cost', 'inventory-reporter')->assertOk();

        $report = $this->getJson('/api/reports/products/stock-status?q=Report%20Cost%20Protected')
            ->assertOk()
            ->assertJsonPath('data.0.average_cost', null)
            ->assertJsonPath('data.0.stock_value', null)
            ->assertJsonPath('meta.totals.stock_value', null);

        $this->assertSame('2.000000', $report->json('data.0.quantity'));
        $this->assertNoInternalIds($report->json());
    }

    public function test_stock_status_is_tenant_scoped_and_requires_report_permission(): void
    {
        $owner = $this->signInOwner('stock-status-a')->assertOk();
        $productUlid = $this->createProduct('Tenant A Secret Stock', '1.000000');
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $this->balance($product, app(TenantContext::class)->warehouseId(), '7.000000', '5.0000');

        $cashierRole = collect($this->getJson('/api/roles')->assertOk()->json())
            ->firstWhere('code', 'cashier');

        $this->postJson('/api/memberships', [
            'name' => 'No Report Cashier',
            'username' => 'no-report-cashier',
            'recovery_email' => 'no-report-cashier@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashierRole['ulid']],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('stock-status-a', 'no-report-cashier')->assertOk();

        $this->getJson('/api/reports/products/stock-status')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FORBIDDEN');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('stock-status-b')->assertOk();

        $foreign = $this->getJson('/api/reports/products/stock-status?q=Tenant%20A%20Secret')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->assertSame([], $foreign->json('data'));
    }

    private function createProduct(string $name, string $reorder): string
    {
        return (string) $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'reorder_level' => $reorder,
        ])->assertCreated()->json('ulid');
    }

    private function balance(Product $product, int $warehouseId, string $quantity, string $cost): void
    {
        $context = app(TenantContext::class);

        StockBalance::query()->updateOrCreate(
            [
                'tenant_id' => $context->tenantId(),
                'warehouse_id' => $warehouseId,
                'product_id' => $product->id,
            ],
            [
                'branch_id' => $context->branchId(),
                'quantity' => $quantity,
                'average_cost' => $cost,
                'stock_value' => bcmul($quantity, $cost, 4),
            ],
        );
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
