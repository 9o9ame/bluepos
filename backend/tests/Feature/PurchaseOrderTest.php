<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
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
}
