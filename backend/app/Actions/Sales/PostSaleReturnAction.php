<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
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

            if (! $document->sale || $document->sale->status !== SaleStatus::Posted) {
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

                $this->postMovement->execute([
                    'warehouse' => $document->warehouse,
                    'product' => $line->product,
                    'movement_type' => StockMovementType::SaleReturn,
                    'quantity' => (string) $line->stock_quantity,
                    'reference_type' => 'sale_return',
                    'reference_ulid' => $document->ulid,
                    'reference_line_ulid' => $line->ulid,
                    'occurred_at' => $document->return_date?->copy()->startOfDay() ?? now(),
                    'idempotency_key' => 'sale_return:'.$document->ulid.':'.$line->ulid,
                    'update_average_cost' => false,
                    'notes' => $line->reason,
                ]);
            }

            $document->status = SaleReturnStatus::Posted;
            $document->posted_by = $this->tenantContext->userId();
            $document->posted_at = now();
            $document->updated_by = $this->tenantContext->userId();
            $document->save();

            $this->audit->record('SALE_RETURN_POSTED', [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
                'sale_ulid' => $document->sale->ulid,
                'line_count' => $document->lines->count(),
                'grand_total' => (string) $document->grand_total,
            ]);

            return $document->fresh(CreateSaleReturnAction::with()) ?? $document;
        });
    }
}
