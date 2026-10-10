<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrderAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = PurchaseOrder::query()
                ->forTenant($this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $order->status === 'cancelled') {
                return $this->freshOrder($order);
            }

            if ((string) $order->status !== 'open') {
                throw ValidationException::withMessages([
                    'purchase_order_ulid' => 'Only open Purchase Orders can be cancelled.',
                ]);
            }

            $lineIds = $order->lines()
                ->orderBy('id')
                ->pluck('id');

            if ($lineIds->isNotEmpty()) {
                $hasPostedReceipts = PurchaseInvoiceLine::query()
                    ->whereIn('purchase_order_line_id', $lineIds)
                    ->whereHas('purchaseInvoice', fn ($query) => $query
                        ->forTenant($this->tenantContext->tenantId())
                        ->where('branch_id', $this->tenantContext->branchId())
                        ->where('warehouse_id', $this->tenantContext->warehouseId())
                        ->where('status', PurchaseInvoiceStatus::Posted->value))
                    ->exists();

                if ($hasPostedReceipts) {
                    throw ValidationException::withMessages([
                        'purchase_order_ulid' => 'Purchase Orders with posted receipts cannot be cancelled.',
                    ]);
                }
            }

            $order->status = 'cancelled';
            $order->save();

            $this->audit->record('PURCHASE_ORDER_CANCELLED', [
                'resource_type' => 'purchase_order',
                'resource_ulid' => $order->ulid,
            ]);

            return $this->freshOrder($order);
        });
    }

    private function freshOrder(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh([
            'supplier',
            'branch',
            'warehouse',
            'lines.product',
            'lines.unit',
        ]) ?? $order;
    }
}
