<?php

namespace App\Actions\Purchases;

use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Exceptions\ApiException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PreparePurchaseOrderAction $prepare,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(
        PurchaseOrder $order,
        array $data,
        string $reason,
    ): PurchaseOrder {
        return DB::transaction(function () use ($order, $data, $reason): PurchaseOrder {
            $order = PurchaseOrder::query()
                ->forTenant($this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new ApiException('NOT_FOUND', 'The requested Purchase Order was not found.', 404);
            }

            if ((string) $order->status !== 'open') {
                throw ValidationException::withMessages([
                    'purchase_order_ulid' => 'Only open Purchase Orders can be edited.',
                ]);
            }

            if ($order->purchaseInvoices()->exists()) {
                throw new ApiException(
                    'PURCHASE_ORDER_RECEIPT_EXISTS',
                    'This Purchase Order already has a linked Purchase Invoice and cannot be edited.',
                    409,
                );
            }

            $before = $order
                ->load(['supplier', 'branch', 'warehouse', 'lines.product', 'lines.unit'])
                ->toArray();

            $prepared = $this->prepare->execute($data);

            if ((int) $prepared['warehouse']->id !== (int) $order->warehouse_id) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Switch to the Purchase Order warehouse before editing it.',
                ]);
            }

            $order->forceFill([
                'supplier_id' => $prepared['supplier']->id,
                'order_date' => $data['order_date'] ?? $order->order_date?->toDateString(),
                'subtotal' => $prepared['subtotal'],
                'discount_amount' => $prepared['discount_amount'],
                'grand_total' => $prepared['grand_total'],
                'notes' => $data['notes'] ?? null,
            ])->save();

            PurchaseOrderLine::query()
                ->where('purchase_order_id', $order->id)
                ->delete();

            foreach ($prepared['lines'] as $line) {
                PurchaseOrderLine::query()->create([
                    'tenant_id' => $order->tenant_id,
                    'purchase_order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'unit_id' => $line['unit']->id,
                    'quantity' => $line['quantity'],
                    'conversion_factor' => $line['conversion_factor'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_price' => $line['unit_price'],
                    'gross_amount' => $line['gross_amount'],
                    'discount_percent' => $line['discount_percent'],
                    'discount_amount' => $line['discount_amount'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'],
                ]);
            }

            $updated = $order->fresh([
                'supplier',
                'branch',
                'warehouse',
                'lines.product',
                'lines.unit',
            ]) ?? $order;

            $this->revisions->execute(
                'purchase_order',
                $order->ulid,
                $reason,
                $before,
                $updated->toArray(),
                (int) $order->branch_id,
                (int) $order->warehouse_id,
            );

            $this->audit->record('PURCHASE_ORDER_UPDATED', [
                'resource_type' => 'purchase_order',
                'resource_ulid' => $order->ulid,
                'line_count' => count($prepared['lines']),
            ]);

            return $updated;
        });
    }
}
