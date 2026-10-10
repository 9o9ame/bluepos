<?php

namespace App\Actions\Purchases;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostPurchaseInvoiceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly RecalculatePurchaseTotalsAction $recalculate,
        private readonly PostPurchaseAccountingAction $postAccounting,
        private readonly EnsureProductSupplierLinkAction $ensureSupplierLink,
        private readonly SyncPurchaseLineToProductAction $syncProduct,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice): PurchaseInvoice {
            $invoice = PurchaseInvoice::query()
                ->with(['warehouse', 'supplier', 'lines.product', 'lines.unit'])
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status === PurchaseInvoiceStatus::Posted) {
                return $invoice->fresh(CreatePurchaseInvoiceAction::with()) ?? $invoice;
            }

            if ($invoice->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one purchase line before posting.',
                ]);
            }

            $warehouse = $invoice->warehouse;
            $supplier = $invoice->supplier;
            if (! $warehouse || $warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Purchases cannot be posted to an inactive warehouse.',
                ]);
            }
            if (! $supplier || ! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'Purchases cannot be posted for an inactive supplier.',
                ]);
            }

            $purchaseOrder = $this->validateLinkedPurchaseOrderReceipts($invoice);

            $this->recalculate->execute($invoice);

            // Accounting and stock posting remain in the same transaction. A missing
            // control account or supplier payable mapping therefore rolls back all
            // stock/product changes as well as the invoice status transition.
            $this->postAccounting->execute($invoice);

            foreach ($invoice->lines as $line) {
                /** @var PurchaseInvoiceLine $line */
                $product = $line->product;
                if (! $product || $product->status !== ProductStatus::Active || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'product_ulid' => 'Purchases cannot be posted for an inactive product.',
                    ]);
                }
                if ($product->track_batch && (! is_string($line->batch_number) || $line->batch_number === '')) {
                    throw ValidationException::withMessages([
                        'batch_number' => 'Batch number is required for this product.',
                    ]);
                }
                if ($product->track_expiry && $line->expiry_date === null) {
                    throw ValidationException::withMessages([
                        'expiry_date' => 'Expiry date is required for this product.',
                    ]);
                }

                [$stockQty, $inventoryUnitCost] = $this->stockQuantityAndUnitCost($line);

                $this->postMovement->execute([
                    'warehouse' => $warehouse,
                    'product' => $product,
                    'movement_type' => StockMovementType::Purchase,
                    'quantity' => $stockQty,
                    'unit_cost' => $inventoryUnitCost,
                    'reference_type' => 'purchase_invoice',
                    'reference_ulid' => $invoice->ulid,
                    'reference_line_ulid' => $line->ulid,
                    'occurred_at' => $invoice->invoice_date?->copy()->startOfDay() ?? now(),
                    'idempotency_key' => 'purchase:'.$invoice->ulid.':'.$line->ulid,
                    'update_average_cost' => true,
                ]);

                $this->ensureSupplierLink->execute(
                    $product,
                    $supplier,
                    $line->supplier_product_code,
                );

                $this->syncProduct->execute($product, $line);
            }

            $invoice->status = PurchaseInvoiceStatus::Posted;
            $invoice->posted_by = $this->tenantContext->userId();
            $invoice->posted_at = now();
            $invoice->updated_by = $this->tenantContext->userId();
            $invoice->save();

            if ($purchaseOrder) {
                $this->refreshPurchaseOrderStatus($purchaseOrder);
            }

            $this->audit->record('PURCHASE_POSTED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
                'line_count' => $invoice->lines->count(),
            ]);

            return $invoice->fresh(CreatePurchaseInvoiceAction::with()) ?? $invoice;
        });
    }

    private function validateLinkedPurchaseOrderReceipts(PurchaseInvoice $invoice): ?PurchaseOrder
    {
        if ($invoice->purchase_order_id === null) {
            return null;
        }

        $purchaseOrder = PurchaseOrder::query()
            ->forTenant($this->tenantContext->tenantId())
            ->whereKey($invoice->purchase_order_id)
            ->where('branch_id', $this->tenantContext->branchId())
            ->where('warehouse_id', $this->tenantContext->warehouseId())
            ->lockForUpdate()
            ->firstOrFail();

        if ((string) $purchaseOrder->status !== 'open') {
            throw ValidationException::withMessages([
                'purchase_order_ulid' => 'Only open Purchase Orders can receive Purchase Invoices.',
            ]);
        }

        $linkedLines = $invoice->lines
            ->filter(fn (PurchaseInvoiceLine $line): bool => $line->purchase_order_line_id !== null);

        if ($linkedLines->isEmpty()) {
            return $purchaseOrder;
        }

        $orderLineIds = $linkedLines
            ->pluck('purchase_order_line_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $orderLines = PurchaseOrderLine::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('purchase_order_id', $purchaseOrder->id)
            ->whereIn('id', $orderLineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($orderLines->count() !== $orderLineIds->count()) {
            throw ValidationException::withMessages([
                'lines' => 'One or more linked Purchase Order lines are invalid.',
            ]);
        }

        $alreadyReceived = PurchaseInvoiceLine::query()
            ->whereIn('purchase_order_line_id', $orderLineIds)
            ->whereHas('purchaseInvoice', fn ($query) => $query
                ->forTenant($this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->where('status', PurchaseInvoiceStatus::Posted->value))
            ->selectRaw('purchase_order_line_id, COALESCE(SUM(base_quantity), 0) as received_base_quantity')
            ->groupBy('purchase_order_line_id')
            ->pluck('received_base_quantity', 'purchase_order_line_id');

        foreach ($linkedLines->groupBy('purchase_order_line_id') as $orderLineId => $invoiceLines) {
            /** @var PurchaseOrderLine $orderLine */
            $orderLine = $orderLines->get((int) $orderLineId);
            $currentBase = $invoiceLines->reduce(
                fn (string $total, PurchaseInvoiceLine $line): string => bcadd(
                    $total,
                    (string) $line->base_quantity,
                    6,
                ),
                '0.000000',
            );
            $priorBase = bcadd((string) ($alreadyReceived->get($orderLineId) ?? '0'), '0', 6);
            $afterPosting = bcadd($priorBase, $currentBase, 6);

            if (bccomp($afterPosting, (string) $orderLine->base_quantity, 6) === 1) {
                throw ValidationException::withMessages([
                    'lines' => 'Received quantity cannot exceed the remaining Purchase Order quantity.',
                ]);
            }
        }

        return $purchaseOrder;
    }

    private function refreshPurchaseOrderStatus(PurchaseOrder $purchaseOrder): void
    {
        if ((string) $purchaseOrder->status === 'cancelled') {
            return;
        }

        $orderLines = PurchaseOrderLine::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($orderLines->isEmpty()) {
            return;
        }

        $received = PurchaseInvoiceLine::query()
            ->whereIn('purchase_order_line_id', $orderLines->pluck('id'))
            ->whereHas('purchaseInvoice', fn ($query) => $query
                ->forTenant($this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->where('status', PurchaseInvoiceStatus::Posted->value))
            ->selectRaw('purchase_order_line_id, COALESCE(SUM(base_quantity), 0) as received_base_quantity')
            ->groupBy('purchase_order_line_id')
            ->pluck('received_base_quantity', 'purchase_order_line_id');

        $fullyReceived = $orderLines->every(function (PurchaseOrderLine $line) use ($received): bool {
            $receivedBase = bcadd((string) ($received->get($line->id) ?? '0'), '0', 6);

            return bccomp($receivedBase, (string) $line->base_quantity, 6) >= 0;
        });

        $nextStatus = $fullyReceived ? 'closed' : 'open';
        if ((string) $purchaseOrder->status === $nextStatus) {
            return;
        }

        $purchaseOrder->status = $nextStatus;
        $purchaseOrder->save();

        if ($fullyReceived) {
            $this->audit->record('PURCHASE_ORDER_CLOSED', [
                'resource_type' => 'purchase_order',
                'resource_ulid' => $purchaseOrder->ulid,
            ]);
        }
    }

    /**
     * Free pieces increase on-hand quantity without additional purchase cost,
     * so average cost is diluted across paid + free base quantity.
     *
     * @return array{0: string, 1: string}
     */
    private function stockQuantityAndUnitCost(PurchaseInvoiceLine $line): array
    {
        $paidBase = (string) $line->base_quantity;
        $unitCost = $this->recalculate->inventoryUnitCost(
            (string) $line->unit_cost,
            (string) $line->conversion_factor,
        );

        $freePcs = (string) ($line->free_pcs ?? '0');
        if (bccomp($freePcs, '0', 6) !== 1) {
            return [$paidBase, $unitCost];
        }

        $freeBase = bcmul($freePcs, (string) $line->conversion_factor, 6);
        $totalBase = bcadd($paidBase, $freeBase, 6);
        $paidValue = bcmul($paidBase, $unitCost, 4);

        if (bccomp($totalBase, '0', 6) !== 1) {
            return [$paidBase, $unitCost];
        }

        return [$totalBase, bcdiv($paidValue, $totalBase, 4)];
    }
}
