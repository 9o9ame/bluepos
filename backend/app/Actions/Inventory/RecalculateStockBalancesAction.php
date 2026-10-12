<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class RecalculateStockBalancesAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{processed_products:int,changed_balances:int,movement_count:int}
     */
    public function execute(): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $branchId = $this->tenantContext->branchId();
        $warehouseId = $this->tenantContext->warehouseId();

        return DB::transaction(function () use ($tenantId, $branchId, $warehouseId): array {
            $movementProductIds = StockMovement::query()
                ->forTenant($tenantId)
                ->where('branch_id', $branchId)
                ->where('warehouse_id', $warehouseId)
                ->distinct()
                ->pluck('product_id');

            $balanceProductIds = StockBalance::query()
                ->forTenant($tenantId)
                ->where('branch_id', $branchId)
                ->where('warehouse_id', $warehouseId)
                ->pluck('product_id');

            $productIds = $movementProductIds
                ->merge($balanceProductIds)
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();

            $changed = 0;
            $movementCount = 0;

            foreach ($productIds as $productId) {
                StockBalance::query()->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => '0.000000',
                    'average_cost' => null,
                    'stock_value' => null,
                    'last_movement_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $balance = StockBalance::query()
                    ->forTenant($tenantId)
                    ->where('branch_id', $branchId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Rebuild in insertion/posting order, not occurred_at order.
                // Weighted-average cost is applied when a movement is posted,
                // including when a document is backdated.
                $movements = StockMovement::query()
                    ->forTenant($tenantId)
                    ->where('branch_id', $branchId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->orderBy('id')
                    ->get();

                $quantity = '0.000000';
                $averageCost = null;
                $lastMovementId = null;

                foreach ($movements as $movement) {
                    $movementCount++;
                    $oldQuantity = $quantity;
                    $movementQuantity = bcadd((string) $movement->quantity, '0', 6);
                    $quantity = bcadd($quantity, $movementQuantity, 6);

                    if ($this->updatesAverageCost($movement)
                        && $movement->unit_cost !== null
                        && bccomp($movementQuantity, '0', 6) === 1) {
                        $unitCost = bcadd((string) $movement->unit_cost, '0', 4);

                        if (bccomp($oldQuantity, '0', 6) <= 0) {
                            $averageCost = $unitCost;
                        } else {
                            $oldValue = bcmul($oldQuantity, $averageCost ?? '0.0000', 4);
                            $incomingValue = bcmul($movementQuantity, $unitCost, 4);
                            $combined = bcadd($oldValue, $incomingValue, 4);
                            $averageCost = bcdiv($combined, $quantity, 4);
                        }
                    }

                    $lastMovementId = $movement->id;
                }

                $stockValue = $averageCost === null
                    ? null
                    : bcmul($quantity, $averageCost, 4);

                $nextQuantity = bcadd($quantity, '0', 6);
                $nextAverageCost = $averageCost === null ? null : bcadd($averageCost, '0', 4);
                $nextStockValue = $stockValue === null ? null : bcadd($stockValue, '0', 4);

                $isChanged =
                    bccomp((string) $balance->quantity, $nextQuantity, 6) !== 0
                    || $this->decimalChanged($balance->average_cost, $nextAverageCost, 4)
                    || $this->decimalChanged($balance->stock_value, $nextStockValue, 4)
                    || (int) ($balance->last_movement_id ?? 0) !== (int) ($lastMovementId ?? 0);

                if ($isChanged) {
                    $changed++;
                }

                $balance->quantity = $nextQuantity;
                $balance->average_cost = $nextAverageCost;
                $balance->stock_value = $nextStockValue;
                $balance->last_movement_id = $lastMovementId;
                $balance->save();
            }

            $result = [
                'processed_products' => $productIds->count(),
                'changed_balances' => $changed,
                'movement_count' => $movementCount,
            ];

            $this->audit->record('STOCK_BALANCES_RECALCULATED', [
                'resource_type' => 'warehouse',
                'resource_ulid' => $this->tenantContext->warehouse()->ulid,
                ...$result,
            ]);

            return $result;
        });
    }

    private function updatesAverageCost(StockMovement $movement): bool
    {
        return in_array(
            $movement->movement_type,
            [StockMovementType::OpeningBalance, StockMovementType::Purchase],
            true,
        );
    }

    private function decimalChanged(mixed $current, ?string $next, int $scale): bool
    {
        if ($current === null || $next === null) {
            return $current !== null || $next !== null;
        }

        return bccomp((string) $current, $next, $scale) !== 0;
    }
}
