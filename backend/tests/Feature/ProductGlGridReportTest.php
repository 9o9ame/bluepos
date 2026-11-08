<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductGlGridReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_gl_grid_reconciles_opening_in_out_closing_and_scope(): void
    {
        $owner = $this->signInOwner('gl-grid')->assertOk();
        $ctx = app(TenantContext::class);
        $product = $this->product('Grid GL Product');
        $this->move($product, $ctx->warehouseId(), '2026-09-30 12:00:00', '10.000000', StockMovementType::OpeningBalance);
        $this->move($product, $ctx->warehouseId(), '2026-10-01 12:00:00', '3.500000', StockMovementType::Purchase);
        $this->move($product, $ctx->warehouseId(), '2026-10-02 12:00:00', '-6.000000', StockMovementType::Sale);
        $this->move($product, $ctx->warehouseId(), '2026-10-03 12:00:00', '1.000000', StockMovementType::SaleAmendment);

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $ctx->tenantId(),
            'branch_id' => $ctx->branchId(),
            'code' => 'GL-OTHER',
            'name' => 'Other GL Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);
        $this->move($product, $otherWarehouse->id, '2026-10-04 12:00:00', '999.000000', StockMovementType::Purchase);

        $response = $this->getJson('/api/reports/products/gl-grid?from_date=2026-10-01&to_date=2026-10-31&q=Grid%20GL')
            ->assertOk()
            ->assertJsonPath('meta.warehouse.ulid', $owner->json('warehouse.ulid'))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.ulid', $product->ulid)
            ->assertJsonPath('data.0.opening', '10.000000')
            ->assertJsonPath('data.0.debit', '4.500000')
            ->assertJsonPath('data.0.credit', '6.000000')
            ->assertJsonPath('data.0.closing', '8.500000')
            ->assertJsonPath('meta.totals.closing', '8.500000');
        $this->assertNoInternalIds($response->json());

        $this->getJson('/api/reports/products/gl-grid?from_date=2026-10-31&to_date=2026-10-01')
            ->assertUnprocessable();
    }

    public function test_gl_grid_grand_totals_cover_all_pages_and_preserve_negative_stock(): void
    {
        $this->signInOwner('gl-grid-pagination')->assertOk();
        $warehouseId = app(TenantContext::class)->warehouseId();
        $first = $this->product('GL Pagination Alpha');
        $second = $this->product('GL Pagination Beta');

        $this->move($first, $warehouseId, '2026-09-30 12:00:00', '2.250000', StockMovementType::OpeningBalance);
        $this->move($first, $warehouseId, '2026-10-01 12:00:00', '-4.500000', StockMovementType::Sale);
        $this->move($second, $warehouseId, '2026-09-30 12:00:00', '1.000000', StockMovementType::OpeningBalance);
        $this->move($second, $warehouseId, '2026-10-02 12:00:00', '3.125000', StockMovementType::Purchase);
        // Future movements must not leak into the as-of closing balance.
        $this->move($second, $warehouseId, '2026-11-02 12:00:00', '100.000000', StockMovementType::Purchase);

        $base = '/api/reports/products/gl-grid?from_date=2026-10-01&to_date=2026-10-31&q=GL%20Pagination&per_page=1';
        $firstPage = $this->getJson($base.'&page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.totals.opening', '3.250000')
            ->assertJsonPath('meta.totals.debit', '3.125000')
            ->assertJsonPath('meta.totals.credit', '4.500000')
            ->assertJsonPath('meta.totals.closing', '1.875000');

        $secondPage = $this->getJson($base.'&page=2')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.totals.closing', '1.875000');

        $rows = collect([$firstPage->json('data.0'), $secondPage->json('data.0')])
            ->keyBy('product.ulid');
        $this->assertSame('-2.250000', $rows[$first->ulid]['closing']);
        $this->assertSame('4.125000', $rows[$second->ulid]['closing']);

        foreach ($rows as $row) {
            $calculatedClosing = bcsub(
                bcadd($row['opening'], $row['debit'], 6),
                $row['credit'], 6,
            );
            $this->assertSame($calculatedClosing, $row['closing']);
        }
        $this->assertNoInternalIds($firstPage->json());
        $this->assertNoInternalIds($secondPage->json());
    }

    public function test_gl_grid_cannot_see_other_tenant(): void
    {
        $this->signInOwner('gl-grid-a')->assertOk();
        $product = $this->product('Isolated GL Item');
        $this->move($product, app(TenantContext::class)->warehouseId(), '2026-10-02 12:00:00', '7.000000', StockMovementType::Purchase);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('gl-grid-b')->assertOk();
        $this->getJson('/api/reports/products/gl-grid?from_date=2026-10-01&to_date=2026-10-31&q=Isolated%20GL')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function product(string $name): Product
    {
        $ulid = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');

        return Product::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function move(Product $product, int $warehouseId, string $date, string $qty, StockMovementType $type): void
    {
        $ctx = app(TenantContext::class);
        StockMovement::query()->create([
            'tenant_id' => $ctx->tenantId(),
            'branch_id' => $ctx->branchId(),
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $qty,
            'unit_cost' => '1.0000',
            'total_cost' => bcmul($qty, '1.0000', 4),
            'reference_type' => 'test',
            'reference_ulid' => (string) Str::ulid(),
            'occurred_at' => $date,
            'posted_by' => $ctx->userId(),
        ]);
    }

    private function unitUlid(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) return $unit['ulid'];
        }
        $this->fail('Missing unit '.$code);
    }
}
