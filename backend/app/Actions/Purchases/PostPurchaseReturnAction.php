<?php

namespace App\Actions\Purchases;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockBalance;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostPurchaseReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly RecalculatePurchaseReturnTotalsAction $recalculate,
        private readonly PostPurchaseReturnAccountingAction $accounting,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PurchaseReturn $document): PurchaseReturn
    {
        return DB::transaction(function () use ($document): PurchaseReturn {
            $document = PurchaseReturn::query()
                ->with([
                    'warehouse',
                    'supplier',
                    'purchaseInvoice',
                    'lines.product',
                    'lines.purchaseInvoiceLine',
                ])
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === PurchaseReturnStatus::Posted) {
                return $document->fresh(CreatePurchaseReturnAction::with()) ?? $document;
            }

            if ($document->branch_id !== $this->tenantContext->branchId()
                || $document->warehouse_id !== $this->tenantContext->warehouseId()) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Switch to the return branch and warehouse before posting this purchase return.',
                ]);
            }

            if ($document->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one return line before posting.',
                ]);
            }

            $invoiceMode = $document->purchase_invoice_id !== null;
            $invoice = null;

            if ($invoiceMode) {
                $invoice = PurchaseInvoice::query()
                    ->forTenant($this->tenantContext->tenantId())
                    ->whereKey($document->purchase_invoice_id)
                    ->lockForUpdate()
                    ->first();

                if (! $invoice || $invoice->status !== PurchaseInvoiceStatus::Posted) {
                    throw ValidationException::withMessages([
                        'purchase_ulid' => 'Original purchase invoice must remain posted.',
                    ]);
                }
            }

            $warehouse = $document->warehouse;
            if (! $warehouse || $warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Purchase returns cannot be posted to an inactive warehouse.',
                ]);
            }

            if (! $document->supplier || ! $document->supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_ulid' => 'An active supplier is required before posting a purchase return.',
                ]);
            }

            $this->recalculate->execute($document);
            $this->accounting->execute($document);

            foreach ($document->lines as $line) {
                /** @var PurchaseReturnLine $line */
                $product = $line->product;
                if (! $product || $product->status !== ProductStatus::Active || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'product_ulid' => 'Purchase returns cannot be posted for an inactive product.',
                    ]);
                }

                if ($invoiceMode) {
                    $purchaseLine = PurchaseInvoiceLine::query()
                        ->whereKey($line->purchase_invoice_line_id)
                        ->where('purchase_invoice_id', $invoice?->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $purchaseLine) {
                        throw ValidationException::withMessages([
                            'purchase_line_ulid' => 'A return line references an invalid purchase line.',
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

                    $this->recalculate->assertReturnable(
                        $purchaseLine,
                        (string) $line->base_quantity,
                        $document->id,
                    );
                } elseif ($line->purchase_invoice_line_id !== null) {
                    throw new ApiException(
                        'GENERAL_PURCHASE_RETURN_LINE_INVALID',
                        'General purchase return lines cannot reference an original purchase line.',
                        422,
                    );
                }

                if ($invoiceMode) {
                    $inventoryUnitCost = $this->recalculate->inventoryUnitCost(
                        (string) $line->unit_cost,
                        (string) $line->conversion_factor,
                    );
                } else {
                    $balance = StockBalance::query()
                        ->where('tenant_id', $document->tenant_id)
                        ->where('warehouse_id', $document->warehouse_id)
                        ->where('product_id', $line->product_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $balance || $balance->average_cost === null) {
                        throw new ApiException(
                            'GENERAL_PURCHASE_RETURN_COST_REQUIRED',
                            'Current warehouse average cost is required before posting a general purchase return.',
                            422,
                        );
                    }

                    $inventoryUnitCost = bcadd((string) $balance->average_cost, '0', 4);
                }

                $negativeQty = bcmul((string) $line->base_quantity, '-1', 6);

                $this->postMovement->execute([
                    'warehouse' => $warehouse,
                    'product' => $product,
                    'movement_type' => StockMovementType::PurchaseReturn,
                    'quantity' => $negativeQty,
                    'unit_cost' => $inventoryUnitCost,
                    'reference_type' => 'purchase_return',
                    'reference_ulid' => $document->ulid,
                    'reference_line_ulid' => $line->ulid,
                    'occurred_at' => $document->return_date?->copy()->startOfDay() ?? now(),
                    'idempotency_key' => 'purchase_return:'.$document->ulid.':'.$line->ulid,
                    'update_average_cost' => false,
                    'notes' => $line->reason,
                ]);
            }

            $document->status = PurchaseReturnStatus::Posted;
            $document->posted_by = $this->tenantContext->userId();
            $document->posted_at = now();
            $document->updated_by = $this->tenantContext->userId();
            $document->save();

            $this->audit->record('PURCHASE_RETURN_POSTED', [
                'resource_type' => 'purchase_return',
                'resource_ulid' => $document->ulid,
                'purchase_ulid' => $document->purchaseInvoice?->ulid,
                'return_mode' => $invoiceMode ? 'invoice' : 'general',
                'line_count' => $document->lines->count(),
                'grand_total' => (string) $document->grand_total,
            ]);

            return $document->fresh(CreatePurchaseReturnAction::with()) ?? $document;
        });
    }
}
