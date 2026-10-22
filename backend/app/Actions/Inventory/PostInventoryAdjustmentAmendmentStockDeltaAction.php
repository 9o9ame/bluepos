<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

class PostInventoryAdjustmentAmendmentStockDeltaAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PostStockMovementAction $postMovement,
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param list<array<string,mixed>> $preparedLines
     * @return list<array<string,mixed>>
     */
    public function execute(
        InventoryAdjustment $document,
        array $before,
        array $preparedLines,
        int $revisionNumber,
    ): array {
        $tenantId = $this->tenantContext->tenantId();
        $warehouse = $this->tenantContext->warehouse();

        $beforeByProduct = [];
        foreach (($before['lines'] ?? []) as $line) {
            $productUlid = (string) ($line['product']['ulid'] ?? '');
            if ($productUlid === '') {
                continue;
            }
            $beforeByProduct[$productUlid] = $line;
        }

        $afterByProduct = [];
        foreach ($preparedLines as $line) {
            $afterByProduct[(string) $line['product']['ulid']] = $line;
        }

        $allProducts = array_values(array_unique(array_merge(
            array_keys($beforeByProduct),
            array_keys($afterByProduct),
        )));

        foreach ($allProducts as $productUlid) {
            $oldDelta = bcadd(
                (string) ($beforeByProduct[$productUlid]['quantity_delta'] ?? '0'),
                '0',
                6,
            );
            $newDelta = bcadd(
                (string) ($afterByProduct[$productUlid]['quantity_delta'] ?? '0'),
                '0',
                6,
            );
            $movementDelta = bcsub($newDelta, $oldDelta, 6);

            if (bccomp($movementDelta, '0', 6) === 0) {
                continue;
            }

            /** @var Product|null $product */
            $product = isset($afterByProduct[$productUlid])
                ? $afterByProduct[$productUlid]['product_model']
                : Product::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $productUlid)
                    ->first();

            if (! $product) {
                throw new \RuntimeException('Inventory adjustment amendment product could not be resolved.');
            }

            $unitCost = bcadd(
                (string) (
                    $afterByProduct[$productUlid]['price']
                    ?? $beforeByProduct[$productUlid]['price']
                    ?? '0'
                ),
                '0',
                4,
            );

            $this->postMovement->execute([
                'warehouse' => $warehouse,
                'product' => $product,
                'movement_type' => bccomp($movementDelta, '0', 6) > 0
                    ? StockMovementType::AdjustmentIn
                    : StockMovementType::AdjustmentOut,
                'quantity' => $movementDelta,
                'unit_cost' => $unitCost,
                'reference_type' => 'inventory_adjustment_amendment',
                'reference_ulid' => $document->ulid,
                'reference_line_ulid' => null,
                'occurred_at' => now(),
                'idempotency_key' => 'inventory-adjustment-amend-stock:'.$document->ulid.':'.$revisionNumber.':'.$product->ulid,
                'notes' => 'Inventory adjustment revision '.$revisionNumber,
                'update_average_cost' => false,
            ]);
        }

        $result = [];

        foreach ($preparedLines as $line) {
            /** @var Product $product */
            $product = $line['product_model'];

            $balance = StockBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->first();

            $current = bcadd((string) ($balance?->quantity ?? '0'), '0', 6);
            $effectiveDelta = bcadd((string) $line['quantity_delta'], '0', 6);
            $baseline = bcsub($current, $effectiveDelta, 6);

            $result[] = [
                'ulid' => (string) Str::ulid(),
                'in_stock' => $baseline,
                'qty_plus' => $line['qty_plus'],
                'qty_minus' => $line['qty_minus'],
                'quantity_delta' => $effectiveDelta,
                'after_stock' => $current,
                'price' => $line['price'],
                'amount' => $line['amount'],
                'remarks' => $line['remarks'],
                'product' => $line['product'],
            ];
        }

        return $result;
    }
}
