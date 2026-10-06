<?php

namespace App\Actions\Inventory;

use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryStockTake;
use App\Models\InventoryStockTakeLine;
use App\Models\StockBalance;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostStockTakeAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(InventoryStockTake $document): InventoryStockTake
    {
        return DB::transaction(function () use ($document): InventoryStockTake {
            $document = InventoryStockTake::query()
                ->with(['warehouse', 'lines.product'])
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === StockTakeStatus::Posted) {
                return $document->fresh(['warehouse', 'lines.product']) ?? $document;
            }

            if ($document->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one stock take line before posting.',
                ]);
            }

            $warehouse = $document->warehouse;
            if (! $warehouse || $warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Stock taking cannot be posted to an inactive warehouse.',
                ]);
            }

            foreach ($document->lines as $line) {
                /** @var InventoryStockTakeLine $line */
                $product = $line->product;
                if (! $product || $product->status !== ProductStatus::Active || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'product_ulid' => 'Stock taking cannot be posted for an inactive product.',
                    ]);
                }

                $counted = bcadd((string) $line->counted_quantity, '0', 6);
                if (bccomp($counted, '0', 6) === -1) {
                    throw ValidationException::withMessages([
                        'counted_quantity' => 'Counted quantity cannot be negative.',
                    ]);
                }

                StockBalance::query()->insertOrIgnore([
                    'tenant_id' => $this->tenantContext->tenantId(),
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => '0.000000',
                    'average_cost' => null,
                    'stock_value' => null,
                    'last_movement_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $balance = StockBalance::query()
                    ->where('tenant_id', $this->tenantContext->tenantId())
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $system = bcadd((string) $balance->quantity, '0', 6);
                $variance = bcsub($counted, $system, 6);

                $line->system_quantity = $system;
                $line->variance_quantity = $variance;
                $line->save();

                if (bccomp($variance, '0', 6) !== 0) {
                    $movementType = bccomp($variance, '0', 6) === 1
                        ? StockMovementType::AdjustmentIn
                        : StockMovementType::AdjustmentOut;

                    $this->postMovement->execute([
                        'warehouse' => $warehouse,
                        'product' => $product,
                        'movement_type' => $movementType,
                        'quantity' => $variance,
                        'unit_cost' => $balance->average_cost !== null ? (string) $balance->average_cost : null,
                        'reference_type' => 'inventory_stock_take',
                        'reference_ulid' => $document->ulid,
                        'reference_line_ulid' => $line->ulid,
                        'occurred_at' => $document->count_date?->copy()->startOfDay() ?? now(),
                        'idempotency_key' => 'stock_take:'.$document->ulid.':line:'.$line->ulid,
                        'notes' => $line->notes,
                        'update_average_cost' => false,
                    ]);
                }
            }

            $document->status = StockTakeStatus::Posted;
            $document->posted_by = $this->tenantContext->userId();
            $document->posted_at = now();
            $document->save();

            $this->audit->record('STOCK_TAKE_POSTED', [
                'resource_type' => 'inventory_stock_take',
                'resource_ulid' => $document->ulid,
                'line_count' => $document->lines->count(),
            ]);

            return $document->fresh(['warehouse', 'lines.product']) ?? $document;
        });
    }
}
