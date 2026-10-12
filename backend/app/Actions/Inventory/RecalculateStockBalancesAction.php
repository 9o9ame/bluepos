<?php

namespace App\Actions\Inventory;

use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class RecalculateStockBalancesAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StockLedgerLock $ledgerLock,
    ) {}

    /**
     * Rebuild the mutable stock-balance cache from immutable stock movements.
     *
     * Average cost is intentionally preserved rather than reconstructed here.
     *
     * @return array{
     *     checked_balances:int,
     *     corrected_balances:int,
     *     corrected_products:int,
     *     created_balances:int,
     *     valuation_unavailable:int
     * }
     */
    public function execute(): array
    {
        $tenantId = $this->tenantContext->tenantId();

        return DB::transaction(function () use ($tenantId): array {
            $this->ledgerLock->acquire($tenantId);

            $aggregates = StockMovement::query()
                ->forTenant($tenantId)
                ->join('warehouses', function ($join) use ($tenantId): void {
                    $join->on('warehouses.id', '=', 'stock_movements.warehouse_id')
                        ->where('warehouses.tenant_id', '=', $tenantId);
                })
                ->selectRaw(
                    'stock_movements.warehouse_id, stock_movements.product_id, warehouses.branch_id, '.
                    'SUM(stock_movements.quantity) AS quantity, MAX(stock_movements.id) AS last_movement_id'
                )
                ->groupBy(
                    'stock_movements.warehouse_id',
                    'stock_movements.product_id',
                    'warehouses.branch_id',
                )
                ->get();

            $balances = StockBalance::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->get();

            $balancesByKey = $balances->keyBy(
                fn (StockBalance $balance): string => $this->key(
                    (int) $balance->warehouse_id,
                    (int) $balance->product_id,
                ),
            );

            $warehouseBranches = DB::table('warehouses')
                ->where('tenant_id', $tenantId)
                ->pluck('branch_id', 'id');

            $expectedByKey = $aggregates->keyBy(
                fn (object $row): string => $this->key(
                    (int) $row->warehouse_id,
                    (int) $row->product_id,
                ),
            );

            $correctedBalances = 0;
            $createdBalances = 0;
            $valuationUnavailable = 0;
            $correctedProductIds = [];

            foreach ($aggregates as $row) {
                $warehouseId = (int) $row->warehouse_id;
                $productId = (int) $row->product_id;
                $key = $this->key($warehouseId, $productId);
                $quantity = $this->quantity((string) $row->quantity);
                $branchId = (int) $row->branch_id;
                $lastMovementId = (int) $row->last_movement_id;
                /** @var StockBalance|null $balance */
                $balance = $balancesByKey->get($key);

                if (! $balance) {
                    StockBalance::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'average_cost' => null,
                        'stock_value' => null,
                        'last_movement_id' => $lastMovementId,
                    ]);

                    $correctedBalances++;
                    $createdBalances++;
                    $valuationUnavailable++;
                    $correctedProductIds[$productId] = true;

                    continue;
                }

                if ($this->repairBalance($balance, $branchId, $quantity, $lastMovementId)) {
                    $correctedBalances++;
                    $correctedProductIds[$productId] = true;
                }
            }

            foreach ($balances as $balance) {
                $key = $this->key((int) $balance->warehouse_id, (int) $balance->product_id);

                if ($expectedByKey->has($key)) {
                    continue;
                }

                $branchId = (int) ($warehouseBranches[(int) $balance->warehouse_id] ?? $balance->branch_id);

                if ($this->repairBalance($balance, $branchId, '0.000000', null)) {
                    $correctedBalances++;
                    $correctedProductIds[(int) $balance->product_id] = true;
                }
            }

            return [
                'checked_balances' => count(array_unique([
                    ...$balances->map(fn (StockBalance $balance): string => $this->key(
                        (int) $balance->warehouse_id,
                        (int) $balance->product_id,
                    ))->all(),
                    ...$aggregates->map(fn (object $row): string => $this->key(
                        (int) $row->warehouse_id,
                        (int) $row->product_id,
                    ))->all(),
                ])),
                'corrected_balances' => $correctedBalances,
                'corrected_products' => count($correctedProductIds),
                'created_balances' => $createdBalances,
                'valuation_unavailable' => $valuationUnavailable,
            ];
        }, 3);
    }

    private function repairBalance(
        StockBalance $balance,
        int $branchId,
        string $quantity,
        ?int $lastMovementId,
    ): bool {
        $normalizedCurrent = $this->quantity((string) $balance->quantity);
        $currentLastMovementId = $balance->last_movement_id === null
            ? null
            : (int) $balance->last_movement_id;

        $changed = bccomp($normalizedCurrent, $quantity, 6) !== 0
            || (int) $balance->branch_id !== $branchId
            || $currentLastMovementId !== $lastMovementId;

        if (! $changed) {
            return false;
        }

        $balance->branch_id = $branchId;
        $balance->quantity = $quantity;
        $balance->last_movement_id = $lastMovementId;

        $averageCost = $balance->average_cost === null
            ? null
            : bcadd((string) $balance->average_cost, '0', 4);

        $balance->stock_value = $averageCost === null
            ? null
            : bcmul($quantity, $averageCost, 4);

        $balance->save();

        return true;
    }

    private function quantity(string $value): string
    {
        return bcadd($value, '0', 6);
    }

    private function key(int $warehouseId, int $productId): string
    {
        return $warehouseId.':'.$productId;
    }
}
