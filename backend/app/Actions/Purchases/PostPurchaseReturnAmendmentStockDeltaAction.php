<?php

namespace App\Actions\Purchases;

use App\Actions\Inventory\PostStockMovementAction;
use App\Enums\StockMovementType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;

class PostPurchaseReturnAmendmentStockDeltaAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
        private readonly RecalculatePurchaseReturnTotalsAction $recalculate,
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param list<array<string,mixed>> $afterLines
     */
    public function execute(
        PurchaseReturn $document,
        array $before,
        array $afterLines,
        int $revisionNumber,
    ): void {
        $tenantId = $this->tenantContext->tenantId();

        $beforeByProduct = [];
        foreach (($before['lines'] ?? []) as $line) {
            $ulid = (string) ($line['product']['ulid'] ?? '');
            if ($ulid === '') {
                continue;
            }

            $beforeByProduct[$ulid] = bcadd(
                $beforeByProduct[$ulid] ?? '0.000000',
                (string) ($line['base_quantity'] ?? '0'),
                6,
            );
        }

        $afterByProduct = [];
        $preparedByProduct = [];
        foreach ($afterLines as $line) {
            $ulid = (string) $line['product']->ulid;
            $afterByProduct[$ulid] = bcadd(
                $afterByProduct[$ulid] ?? '0.000000',
                (string) $line['base_quantity'],
                6,
            );
            $preparedByProduct[$ulid] = $line;
        }

        foreach (array_values(array_unique(array_merge(
            array_keys($beforeByProduct),
            array_keys($afterByProduct),
        ))) as $productUlid) {
            // Purchase returns move stock OUT. Increasing the effective return
            // therefore posts a negative delta; reducing it posts stock back in.
            $effectiveDelta = bcsub(
                $afterByProduct[$productUlid] ?? '0.000000',
                $beforeByProduct[$productUlid] ?? '0.000000',
                6,
            );

            if (bccomp($effectiveDelta, '0', 6) === 0) {
                continue;
            }

            $movementQty = bcmul($effectiveDelta, '-1', 6);

            /** @var Product|null $product */
            $product = isset($preparedByProduct[$productUlid])
                ? $preparedByProduct[$productUlid]['product']
                : Product::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $productUlid)
                    ->first();

            if (! $product) {
                throw new ApiException(
                    'PURCHASE_RETURN_AMENDMENT_PRODUCT_MISSING',
                    'A product from the previous Purchase Return revision is no longer available.',
                    409,
                );
            }

            $unitCost = $this->effectiveReturnUnitCost($document, $product);

            if ($unitCost === null && isset($preparedByProduct[$productUlid])) {
                $line = $preparedByProduct[$productUlid];

                if ($line['purchase_line'] !== null) {
                    $unitCost = $this->recalculate->inventoryUnitCost(
                        (string) $line['unit_cost'],
                        (string) $line['conversion_factor'],
                    );
                } else {
                    $unitCost = $this->currentAverageCost($document, $product);
                }
            }

            if ($unitCost === null) {
                throw new ApiException(
                    'PURCHASE_RETURN_COST_REQUIRED',
                    'A reliable stock cost is required before amending this Purchase Return.',
                    422,
                );
            }

            $this->postMovement->execute([
                'warehouse' => $document->warehouse,
                'product' => $product,
                'movement_type' => StockMovementType::PurchaseReturnAmendment,
                'quantity' => $movementQty,
                'unit_cost' => $unitCost,
                'reference_type' => 'purchase_return_amendment',
                'reference_ulid' => $document->ulid,
                'reference_line_ulid' => null,
                'occurred_at' => now(),
                'idempotency_key' => 'purchase-return-amend-stock:'.$document->ulid.':'.$revisionNumber.':'.$product->ulid,
                'update_average_cost' => false,
                'notes' => 'Purchase return revision '.$revisionNumber,
            ]);
        }
    }

    private function effectiveReturnUnitCost(
        PurchaseReturn $document,
        Product $product,
    ): ?string {
        $movements = StockMovement::query()
            ->forTenant((int) $document->tenant_id)
            ->where('warehouse_id', $document->warehouse_id)
            ->where('product_id', $product->id)
            ->where(function ($q) use ($document): void {
                $q->where(function ($original) use ($document): void {
                    $original
                        ->where('reference_type', 'purchase_return')
                        ->where('reference_ulid', $document->ulid);
                })->orWhere(function ($amendment) use ($document): void {
                    $amendment
                        ->where('reference_type', 'purchase_return_amendment')
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

        if (bccomp($qty, '0', 6) === 0 || bccomp($cost, '0', 4) === 0) {
            return null;
        }

        $unitCost = bcdiv($cost, $qty, 4);

        return bccomp($unitCost, '0', 4) < 0
            ? bcmul($unitCost, '-1', 4)
            : $unitCost;
    }

    private function currentAverageCost(
        PurchaseReturn $document,
        Product $product,
    ): ?string {
        $cost = StockBalance::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('warehouse_id', $document->warehouse_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->value('average_cost');

        return $cost === null ? null : bcadd((string) $cost, '0', 4);
    }
}
