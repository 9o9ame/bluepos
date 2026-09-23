<?php

namespace App\Actions\Purchases;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
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
        private readonly EnsureProductSupplierLinkAction $ensureSupplierLink,
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

            $this->recalculate->execute($invoice);

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

                $inventoryUnitCost = $this->recalculate->inventoryUnitCost(
                    (string) $line->unit_cost,
                    (string) $line->conversion_factor,
                );

                $this->postMovement->execute([
                    'warehouse' => $warehouse,
                    'product' => $product,
                    'movement_type' => StockMovementType::Purchase,
                    'quantity' => (string) $line->base_quantity,
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
            }

            $invoice->status = PurchaseInvoiceStatus::Posted;
            $invoice->posted_by = $this->tenantContext->userId();
            $invoice->posted_at = now();
            $invoice->updated_by = $this->tenantContext->userId();
            $invoice->save();

            $this->audit->record('PURCHASE_POSTED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
                'line_count' => $invoice->lines->count(),
            ]);

            return $invoice->fresh(CreatePurchaseInvoiceAction::with()) ?? $invoice;
        });
    }
}
