<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_order_is_non_posting_and_server_recalculates_totals(): void
    {
        $this->signInOwner('po-life')->assertOk();

        $pcs = $this->unitUlid('PCS');
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PO-1',
            'name' => 'PO Supplier',
        ])->assertCreated()->json('ulid');

        $productUlid = $this->postJson('/api/products', [
            'name' => 'PO Product',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $order = $this->postJson('/api/purchase-orders', [
            'supplier_ulid' => $supplierUlid,
            'order_date' => '2026-10-10',
            'notes' => 'Order remarks',
            'items' => [
                [
                    'product_ulid' => $productUlid,
                    'unit_ulid' => $pcs,
                    'quantity' => '2.000000',
                    'unit_price' => '100.0000',
                    'discount_percent' => '10.00000000',
                ],
                [
                    'product_ulid' => $productUlid,
                    'unit_ulid' => $pcs,
                    'quantity' => '1.000000',
                    'unit_price' => '50.0000',
                    'discount_amount' => '5.0000',
                ],
            ],
        ])->assertCreated();

        $order->assertJsonPath('document_number', 'PO-000001')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('subtotal', '250.0000')
            ->assertJsonPath('discount_amount', '25.0000')
            ->assertJsonPath('grand_total', '225.0000')
            ->assertJsonPath('items.0.base_quantity', '2.000000')
            ->assertJsonPath('items.0.discount_amount', '20.0000')
            ->assertJsonPath('items.0.line_total', '180.0000')
            ->assertJsonPath('items.1.line_total', '45.0000');

        $this->assertNoInternalIds($order->json());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'PURCHASE_ORDER_CREATED')
                ->where('resource_ulid', $order->json('ulid'))
                ->exists(),
        );

        $show = $this->getJson('/api/purchase-orders/'.$order->json('ulid'))->assertOk();
        $show->assertJsonPath('supplier.ulid', $supplierUlid)
            ->assertJsonPath('notes', 'Order remarks');
        $this->assertNoInternalIds($show->json());

        $list = $this->getJson('/api/purchase-orders?q=PO-000001&date_from=2026-10-10&date_to=2026-10-10')
            ->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $this->assertNoInternalIds($list->json());
    }

    public function test_purchase_order_rejects_invalid_discounts_and_cross_tenant_access(): void
    {
        $this->signInOwner('po-iso-a')->assertOk();

        $pcs = $this->unitUlid('PCS');
        $supplierA = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PO-A',
            'name' => 'Supplier A',
        ])->assertCreated()->json('ulid');

        $productA = $this->postJson('/api/products', [
            'name' => 'Product A',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchase-orders', [
            'supplier_ulid' => $supplierA,
            'items' => [[
                'product_ulid' => $productA,
                'unit_ulid' => $pcs,
                'quantity' => '1.000000',
                'unit_price' => '100.0000',
                'discount_percent' => '5',
                'discount_amount' => '1.0000',
            ]],
        ])->assertStatus(422);

        $orderUlid = $this->postJson('/api/purchase-orders', [
            'supplier_ulid' => $supplierA,
            'items' => [[
                'product_ulid' => $productA,
                'unit_ulid' => $pcs,
                'quantity' => '1.000000',
                'unit_price' => '100.0000',
            ]],
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('po-iso-b')->assertOk();

        $this->getJson('/api/purchase-orders/'.$orderUlid)->assertNotFound();

        $supplierB = $this->postJson('/api/suppliers', [
            'code' => 'SUP-PO-B',
            'name' => 'Supplier B',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchase-orders', [
            'supplier_ulid' => $supplierB,
            'items' => [[
                'product_ulid' => $productA,
                'unit_ulid' => $this->unitUlid('PCS'),
                'quantity' => '1.000000',
                'unit_price' => '1.0000',
            ]],
        ])->assertNotFound();

        $this->assertSame(1, PurchaseOrder::query()->count());
    }

    public function test_purchase_order_generator_uses_posted_sales_and_active_warehouse_stock(): void
    {
        $this->signInOwner('po-generate')->assertOk();

        $pcs = $this->unitUlid('PCS');
        $productUlid = $this->postJson('/api/products', [
            'name' => 'Generator Product',
            'base_unit_ulid' => $pcs,
            'reorder_level' => '8.000000',
        ])->assertCreated()->json('ulid');

        $tenantContext = app(TenantContext::class);
        $warehouse = $tenantContext->warehouse();
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $unit = Unit::query()->where('ulid', $pcs)->firstOrFail();

        StockBalance::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'branch_id' => $tenantContext->branchId(),
            'warehouse_id' => $tenantContext->warehouseId(),
            'product_id' => $product->id,
            'quantity' => '2.000000',
            'average_cost' => '10.0000',
            'stock_value' => '20.0000',
        ]);

        $sale = Sale::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'branch_id' => $tenantContext->branchId(),
            'warehouse_id' => $warehouse->id,
            'document_number' => 'SAL-PO-GEN-1',
            'status' => 'posted',
            'sale_date' => '2026-10-10',
            'price_type' => 'retail',
            'subtotal' => '50.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '50.0000',
            'idempotency_key' => 'po-generator-sale',
            'created_by' => $tenantContext->userId(),
            'posted_at' => now(),
        ]);

        SaleItem::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_factor' => '1.00000000',
            'line_kind' => 'sale',
            'quantity' => '5.000000',
            'stock_quantity' => '5.000000',
            'price_type' => 'retail',
            'unit_price' => '10.0000',
            'gross_amount' => '50.0000',
            'discount_percent' => '0.00000000',
            'discount_amount' => '0.0000',
            'tax_percent' => '0.00000000',
            'tax_amount' => '0.0000',
            'line_total' => '50.0000',
        ]);

        $between = $this->postJson('/api/purchase-orders/generate', [
            'mode' => 'between_dates',
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-10',
        ])->assertOk();

        $between->assertJsonPath('data.0.product.ulid', $productUlid)
            ->assertJsonPath('data.0.in_stock', '2.000000')
            ->assertJsonPath('data.0.stock_value', '20.0000')
            ->assertJsonPath('data.0.consumption', '5.000000')
            ->assertJsonPath('data.0.difference', '3.000000')
            ->assertJsonPath('data.0.suggested_quantity', '3.000000')
            ->assertJsonPath('data.0.unit_price', '10.0000');

        $reorder = $this->postJson('/api/purchase-orders/generate', [
            'mode' => 'reorder_level',
        ])->assertOk();

        $reorder->assertJsonPath('data.0.difference', '6.000000')
            ->assertJsonPath('data.0.suggested_quantity', '6.000000');

        $this->postJson('/api/purchase-orders/generate', [
            'mode' => 'optimum_level',
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    private function unitUlid(string $code): string
    {
        $units = $this->getJson('/api/units')->assertOk()->json();

        foreach ($units as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        if ($code === 'CTN') {
            return $this->postJson('/api/units', [
                'code' => 'CTN',
                'name' => 'Carton',
                'symbol' => 'CTN',
                'allows_decimal' => false,
            ])->assertCreated()->json('ulid');
        }

        $this->fail('Missing unit '.$code);
    }
}
