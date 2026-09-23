<?php

namespace App\Actions\Inventory;

use App\Enums\OpeningBalanceStatus;
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Models\InventoryOpeningBalance;
use App\Models\InventoryOpeningBalanceLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostOpeningBalanceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(InventoryOpeningBalance $document): InventoryOpeningBalance
    {
        return DB::transaction(function () use ($document): InventoryOpeningBalance {
            $document = InventoryOpeningBalance::query()
                ->with(['warehouse', 'lines.product'])
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === OpeningBalanceStatus::Posted) {
                return $document->fresh(['warehouse', 'lines.product']) ?? $document;
            }

            if ($document->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one opening balance line before posting.',
                ]);
            }

            $warehouse = $document->warehouse;
            if (! $warehouse || $warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Opening stock cannot be posted to an inactive warehouse.',
                ]);
            }

            foreach ($document->lines as $line) {
                /** @var InventoryOpeningBalanceLine $line */
                $product = $line->product;
                if (! $product || $product->status !== ProductStatus::Active || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'product_ulid' => 'Opening stock cannot be posted for an inactive product.',
                    ]);
                }

                if (bccomp((string) $line->quantity, '0', 6) !== 1) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Opening quantity must be greater than zero.',
                    ]);
                }

                $expectedTotal = bcmul((string) $line->quantity, (string) $line->unit_cost, 4);
                $line->total_cost = $expectedTotal;
                $line->save();

                $this->postMovement->execute([
                    'warehouse' => $warehouse,
                    'product' => $product,
                    'movement_type' => StockMovementType::OpeningBalance,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => (string) $line->unit_cost,
                    'reference_type' => 'inventory_opening_balance',
                    'reference_ulid' => $document->ulid,
                    'reference_line_ulid' => $line->ulid,
                    'occurred_at' => $document->document_date?->copy()->startOfDay() ?? now(),
                    'idempotency_key' => 'opening_balance:'.$document->ulid.':line:'.$line->ulid,
                    'update_average_cost' => true,
                ]);
            }

            $document->status = OpeningBalanceStatus::Posted;
            $document->posted_by = $this->tenantContext->userId();
            $document->posted_at = now();
            $document->save();

            $this->audit->record('OPENING_BALANCE_POSTED', [
                'resource_type' => 'inventory_opening_balance',
                'resource_ulid' => $document->ulid,
                'line_count' => $document->lines->count(),
            ]);

            return $document->fresh(['warehouse', 'lines.product']) ?? $document;
        });
    }
}
