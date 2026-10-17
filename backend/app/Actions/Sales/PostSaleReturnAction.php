<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostSaleReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly RecalculateSaleReturnTotalsAction $recalculate,
        private readonly PostSaleReturnAccountingAction $postAccounting,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SaleReturn $document): SaleReturn
    {
        return DB::transaction(function () use ($document): SaleReturn {
            $document = SaleReturn::query()
                ->with([
                    'warehouse',
                    'sale',
                    'lines.product',
                    'lines.saleItem',
                ])
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === SaleReturnStatus::Posted) {
                return $document->fresh(CreateSaleReturnAction::with()) ?? $document;
            }

            if ($document->branch_id !== $this->tenantContext->branchId()
                || $document->warehouse_id !== $this->tenantContext->warehouseId()) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Switch to the return branch and warehouse before posting this sales return.',
                ]);
            }

            if ($document->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one return line before posting.',
                ]);
            }

            $invoiceMode = $document->sale_id !== null;
            if ($invoiceMode && (! $document->sale || $document->sale->status !== SaleStatus::Posted)) {
                throw ValidationException::withMessages([
                    'sale_ulid' => 'Original sale must remain posted.',
                ]);
            }

            if (! $document->warehouse || $document->warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Sales returns cannot be posted to an inactive warehouse.',
                ]);
            }

            $this->recalculate->execute($document);

            foreach ($document->lines as $line) {
                /** @var SaleReturnLine $line */
                $originalUnitCost = null;

                if ($invoiceMode) {
                    $saleItem = SaleItem::query()
                        ->whereKey($line->sale_item_id)
                        ->where('sale_id', $document->sale_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $saleItem) {
                        throw ValidationException::withMessages([
                            'lines' => 'An original sale line is no longer available.',
                        ]);
                    }

                    $this->recalculate->assertReturnable(
                        $saleItem,
                        (string) $line->quantity,
                        $document->id,
                    );

                    $saleMovement = StockMovement::query()
                        ->where('tenant_id', $document->tenant_id)
                        ->where('warehouse_id', $document->warehouse_id)
                        ->where('product_id', $line->product_id)
                        ->where('movement_type', StockMovementType::Sale->value)
                        ->where('reference_type', 'sale')
                        ->where('reference_ulid', $document->sale->ulid)
                        ->where('reference_line_ulid', $saleItem->ulid)
                        ->first();

                    $originalUnitCost = $saleMovement?->unit_cost === null
                        ? null
                        : bcadd((string) $saleMovement->unit_cost, '0', 4);
                } else {
                    if ($line->sale_item_id !== null) {
                        throw new ApiException(
                            'GENERAL_RETURN_LINE_INVALID',
                            'General sales return lines cannot reference an original sale item.',
                            422,
                        );
                    }

                    $balance = StockBalance::query()
                        ->where('tenant_id', $document->tenant_id)
                        ->where('warehouse_id', $document->warehouse_id)
                        ->where('product_id', $line->product_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $balance || $balance->average_cost === null) {
                        throw new ApiException(
                            'GENERAL_RETURN_COST_REQUIRED',
                            'Current warehouse average cost is required before posting a general sales return.',
                            422,
                        );
                    }

                    $originalUnitCost = bcadd((string) $balance->average_cost, '0', 4);
                }

                if ($originalUnitCost === null) {
                    throw new ApiException(
                        'SALE_RETURN_COST_REQUIRED',
                        $invoiceMode
                            ? 'The original sale cost snapshot is required before posting this return.'
                            : 'Current warehouse average cost is required before posting this general return.',
                        422,
                    );
                }

                $this->postMovement->execute([
                    'warehouse' => $document->warehouse,
                    'product' => $line->product,
                    'movement_type' => StockMovementType::SaleReturn,
                    'quantity' => (string) $line->stock_quantity,
                    'unit_cost' => $originalUnitCost,
                    'reference_type' => 'sale_return',
                    'reference_ulid' => $document->ulid,
                    'reference_line_ulid' => $line->ulid,
                    'occurred_at' => $document->return_date?->copy()->startOfDay() ?? now(),
                    'idempotency_key' => 'sale_return:'.$document->ulid.':'.$line->ulid,
                    'update_average_cost' => false,
                    'notes' => $line->reason,
                ]);
            }

            $this->postAccounting->execute($document);

            $document->status = SaleReturnStatus::Posted;
            $document->posted_by = $this->tenantContext->userId();
            $document->posted_at = now();
            $document->updated_by = $this->tenantContext->userId();
            $document->save();

            $this->audit->record('SALE_RETURN_POSTED', [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
                'sale_ulid' => $document->sale?->ulid,
                'return_mode' => $invoiceMode ? 'invoice' : 'general',
                'line_count' => $document->lines->count(),
                'grand_total' => (string) $document->grand_total,
            ]);

            return $document->fresh(CreateSaleReturnAction::with()) ?? $document;
        });
    }
}
