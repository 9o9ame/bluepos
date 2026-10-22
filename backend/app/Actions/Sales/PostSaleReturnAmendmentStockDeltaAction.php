<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\StockMovementType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;

class PostSaleReturnAmendmentStockDeltaAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param list<array<string,mixed>> $afterLines
     */
    public function execute(
        SaleReturn $document,
        array $before,
        array $afterLines,
        int $revisionNumber,
    ): string {
        $tenantId = $this->tenantContext->tenantId();

        $beforeByProduct = [];
        foreach (($before['lines'] ?? []) as $line) {
            $ulid = (string) ($line['product']['ulid'] ?? '');
            if ($ulid === '') {
                continue;
            }

            $beforeByProduct[$ulid] = bcadd(
                $beforeByProduct[$ulid] ?? '0.000000',
                (string) ($line['stock_quantity'] ?? '0'),
                6,
            );
        }

        $afterByProduct = [];
        $preparedByProduct = [];
        foreach ($afterLines as $line) {
            $ulid = (string) $line['product']->ulid;
            $afterByProduct[$ulid] = bcadd(
                $afterByProduct[$ulid] ?? '0.000000',
                (string) $line['stock_quantity'],
                6,
            );
            $preparedByProduct[$ulid] = $line;
        }

        foreach (array_values(array_unique(array_merge(
            array_keys($beforeByProduct),
            array_keys($afterByProduct),
        ))) as $productUlid) {
            $delta = bcsub(
                $afterByProduct[$productUlid] ?? '0.000000',
                $beforeByProduct[$productUlid] ?? '0.000000',
                6,
            );

            if (bccomp($delta, '0', 6) === 0) {
                continue;
            }

            /** @var Product|null $product */
            $product = isset($preparedByProduct[$productUlid])
                ? $preparedByProduct[$productUlid]['product']
                : Product::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $productUlid)
                    ->first();

            if (! $product) {
                throw new ApiException(
                    'SALE_RETURN_AMENDMENT_PRODUCT_MISSING',
                    'A product from the previous return revision is no longer available.',
                    409,
                );
            }

            if (bccomp($delta, '0', 6) < 0) {
                $balanceQty = StockBalance::query()
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $document->warehouse_id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->value('quantity');

                $required = bcsub('0.000000', $delta, 6);
                if ($balanceQty === null || bccomp((string) $balanceQty, $required, 6) < 0) {
                    throw new ApiException(
                        'SALE_RETURN_AMENDMENT_STOCK_CONSUMED',
                        'Returned stock has already been consumed and cannot be removed by this amendment.',
                        409,
                    );
                }
            }

            $unitCost = $this->effectiveReturnUnitCost($document, $product);

            if ($unitCost === null && isset($preparedByProduct[$productUlid])) {
                $unitCost = $this->sourceUnitCost(
                    $document,
                    $preparedByProduct[$productUlid],
                    $product,
                );
            }

            if ($unitCost === null) {
                throw new ApiException(
                    'SALE_RETURN_COST_REQUIRED',
                    'A reliable stock cost is required before amending this sales return.',
                    422,
                );
            }

            $this->postMovement->execute([
                'warehouse' => $document->warehouse,
                'product' => $product,
                'movement_type' => StockMovementType::SaleReturnAmendment,
                'quantity' => $delta,
                'unit_cost' => $unitCost,
                'reference_type' => 'sale_return_amendment',
                'reference_ulid' => $document->ulid,
                'reference_line_ulid' => null,
                'occurred_at' => now(),
                'idempotency_key' => 'sale-return-amend-stock:'.$document->ulid.':'.$revisionNumber.':'.$product->ulid,
                'update_average_cost' => false,
                'notes' => 'Sales return revision '.$revisionNumber,
            ]);
        }

        return $this->effectiveReturnedCost($document);
    }

    private function effectiveReturnUnitCost(
        SaleReturn $document,
        Product $product,
    ): ?string {
        $movements = StockMovement::query()
            ->forTenant((int) $document->tenant_id)
            ->where('warehouse_id', $document->warehouse_id)
            ->where('product_id', $product->id)
            ->where(function ($q) use ($document): void {
                $q->where(function ($original) use ($document): void {
                    $original
                        ->where('reference_type', 'sale_return')
                        ->where('reference_ulid', $document->ulid);
                })->orWhere(function ($amendment) use ($document): void {
                    $amendment
                        ->where('reference_type', 'sale_return_amendment')
                        ->where('reference_ulid', $document->ulid);
                });
            })
            ->get(['quantity', 'total_cost']);

        $qty = '0.000000';
        $cost = '0.0000';
        foreach ($movements as $movement) {
            if ($movement->total_cost === null) {
                continue;
            }
            $qty = bcadd($qty, (string) $movement->quantity, 6);
            $cost = bcadd($cost, (string) $movement->total_cost, 4);
        }

        if (bccomp($qty, '0', 6) <= 0 || bccomp($cost, '0', 4) <= 0) {
            return null;
        }

        return bcdiv($cost, $qty, 4);
    }

    /**
     * @param array<string,mixed> $line
     */
    private function sourceUnitCost(
        SaleReturn $document,
        array $line,
        Product $product,
    ): ?string {
        if ($line['sale_item'] !== null && $document->sale) {
            $movement = StockMovement::query()
                ->forTenant((int) $document->tenant_id)
                ->where('warehouse_id', $document->warehouse_id)
                ->where('product_id', $product->id)
                ->where('movement_type', StockMovementType::Sale->value)
                ->where('reference_type', 'sale')
                ->where('reference_ulid', $document->sale->ulid)
                ->where('reference_line_ulid', $line['sale_item']->ulid)
                ->first();

            return $movement?->unit_cost === null
                ? null
                : bcadd((string) $movement->unit_cost, '0', 4);
        }

        $cost = StockBalance::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('warehouse_id', $document->warehouse_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->value('average_cost');

        return $cost === null ? null : bcadd((string) $cost, '0', 4);
    }

    private function effectiveReturnedCost(SaleReturn $document): string
    {
        $total = '0.0000';

        $movements = StockMovement::query()
            ->forTenant((int) $document->tenant_id)
            ->where('warehouse_id', $document->warehouse_id)
            ->where(function ($q) use ($document): void {
                $q->where(function ($original) use ($document): void {
                    $original
                        ->where('reference_type', 'sale_return')
                        ->where('reference_ulid', $document->ulid);
                })->orWhere(function ($amendment) use ($document): void {
                    $amendment
                        ->where('reference_type', 'sale_return_amendment')
                        ->where('reference_ulid', $document->ulid);
                });
            })
            ->get(['total_cost']);

        foreach ($movements as $movement) {
            if ($movement->total_cost !== null) {
                $total = bcadd($total, (string) $movement->total_cost, 4);
            }
        }

        return bccomp($total, '0', 4) < 0 ? '0.0000' : $total;
    }
}
