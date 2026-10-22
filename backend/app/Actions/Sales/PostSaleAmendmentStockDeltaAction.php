<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\StockMovementType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;

class PostSaleAmendmentStockDeltaAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param list<array<string,mixed>> $preparedLines
     */
    public function execute(
        Sale $sale,
        array $before,
        array $preparedLines,
        int $revisionNumber,
    ): string {
        $tenantId = $this->tenantContext->tenantId();

        if ((int) $sale->tenant_id !== $tenantId) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $sale->loadMissing('warehouse');

        $beforeByProduct = [];
        foreach (($before['items'] ?? []) as $item) {
            $productUlid = (string) ($item['product']['ulid'] ?? '');
            if ($productUlid === '') {
                continue;
            }

            $beforeByProduct[$productUlid] = bcadd(
                $beforeByProduct[$productUlid] ?? '0.000000',
                (string) ($item['stock_quantity'] ?? '0'),
                6,
            );
        }

        $afterByProduct = [];
        $products = [];
        foreach ($preparedLines as $line) {
            $productUlid = (string) $line['product']->ulid;
            $products[$productUlid] = $line['product'];
            $afterByProduct[$productUlid] = bcadd(
                $afterByProduct[$productUlid] ?? '0.000000',
                (string) $line['stock_quantity'],
                6,
            );
        }

        $productUlids = array_values(array_unique(array_merge(
            array_keys($beforeByProduct),
            array_keys($afterByProduct),
        )));

        foreach ($productUlids as $productUlid) {
            /** @var Product|null $product */
            $product = $products[$productUlid] ?? Product::query()
                ->forTenant($tenantId)
                ->where('ulid', $productUlid)
                ->first();

            if (! $product) {
                throw new ApiException(
                    'SALE_AMENDMENT_PRODUCT_MISSING',
                    'A product from the previous invoice revision is no longer available.',
                    409,
                );
            }

            $beforeQty = $beforeByProduct[$productUlid] ?? '0.000000';
            $afterQty = $afterByProduct[$productUlid] ?? '0.000000';
            $soldDelta = bcsub($afterQty, $beforeQty, 6);

            if (bccomp($soldDelta, '0', 6) === 0) {
                continue;
            }

            // More sold => negative stock. Less sold => positive stock restoration.
            $movementQty = bcsub('0.000000', $soldDelta, 6);

            $unitCost = bccomp($movementQty, '0', 6) < 0
                ? $this->currentAverageCost($sale, $product)
                : $this->effectiveSaleCost($sale, $product, $beforeQty);

            $this->postMovement->execute([
                'warehouse' => $sale->warehouse,
                'product' => $product,
                'movement_type' => StockMovementType::SaleAmendment,
                'quantity' => $movementQty,
                'unit_cost' => $unitCost,
                'reference_type' => 'sale_amendment',
                'reference_ulid' => $sale->ulid,
                'reference_line_ulid' => null,
                'occurred_at' => now(),
                'idempotency_key' => 'sale-amend-stock:'.$sale->ulid.':'.$revisionNumber.':'.$product->ulid,
                'update_average_cost' => false,
                'notes' => 'Sale invoice revision '.$revisionNumber,
            ]);
        }

        return $this->effectiveCogs($sale);
    }

    private function currentAverageCost(Sale $sale, Product $product): ?string
    {
        $cost = StockBalance::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('warehouse_id', $sale->warehouse_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->value('average_cost');

        return $cost === null ? null : bcadd((string) $cost, '0', 4);
    }

    private function effectiveSaleCost(
        Sale $sale,
        Product $product,
        string $effectiveSoldQuantity,
    ): ?string {
        if (bccomp($effectiveSoldQuantity, '0', 6) <= 0) {
            return $this->currentAverageCost($sale, $product);
        }

        $totalCost = StockMovement::query()
            ->forTenant((int) $sale->tenant_id)
            ->where('warehouse_id', $sale->warehouse_id)
            ->where('product_id', $product->id)
            ->where(function ($q) use ($sale): void {
                $q->where(function ($original) use ($sale): void {
                    $original
                        ->where('reference_type', 'sale')
                        ->where('reference_ulid', $sale->ulid);
                })->orWhere(function ($amendment) use ($sale): void {
                    $amendment
                        ->where('reference_type', 'sale_amendment')
                        ->where('reference_ulid', $sale->ulid);
                });
            })
            ->lockForUpdate()
            ->sum('total_cost');

        $effectiveCost = bcsub('0.0000', bcadd((string) $totalCost, '0', 4), 4);

        if (bccomp($effectiveCost, '0', 4) <= 0) {
            return $this->currentAverageCost($sale, $product);
        }

        return bcdiv($effectiveCost, $effectiveSoldQuantity, 4);
    }

    private function effectiveCogs(Sale $sale): string
    {
        $totalCost = StockMovement::query()
            ->forTenant((int) $sale->tenant_id)
            ->where('warehouse_id', $sale->warehouse_id)
            ->where(function ($q) use ($sale): void {
                $q->where(function ($original) use ($sale): void {
                    $original
                        ->where('reference_type', 'sale')
                        ->where('reference_ulid', $sale->ulid);
                })->orWhere(function ($amendment) use ($sale): void {
                    $amendment
                        ->where('reference_type', 'sale_amendment')
                        ->where('reference_ulid', $sale->ulid);
                });
            })
            ->lockForUpdate()
            ->sum('total_cost');

        $cogs = bcsub('0.0000', bcadd((string) $totalCost, '0', 4), 4);

        return bccomp($cogs, '0', 4) < 0 ? '0.0000' : $cogs;
    }
}
