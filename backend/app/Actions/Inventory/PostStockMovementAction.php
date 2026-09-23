<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostStockMovementAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{
     *     warehouse: Warehouse,
     *     product: Product,
     *     movement_type: StockMovementType|string,
     *     quantity: string,
     *     unit_cost?: string|null,
     *     reference_type: string,
     *     reference_ulid?: string|null,
     *     reference_line_ulid?: string|null,
     *     occurred_at?: \DateTimeInterface|string|null,
     *     idempotency_key?: string|null,
     *     notes?: string|null,
     *     update_average_cost?: bool
     * }  $data
     */
    public function execute(array $data): StockMovement
    {
        $tenantId = $this->tenantContext->tenantId();
        $warehouse = $data['warehouse'];
        $product = $data['product'];

        if ((int) $warehouse->tenant_id !== $tenantId || (int) $product->tenant_id !== $tenantId) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ($warehouse->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages([
                'warehouse_ulid' => 'Opening stock cannot be posted to an inactive warehouse.',
            ]);
        }

        $movementType = $data['movement_type'] instanceof StockMovementType
            ? $data['movement_type']
            : StockMovementType::from((string) $data['movement_type']);

        $quantity = $this->normalizeQuantity((string) $data['quantity']);
        $unitCost = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null
            ? $this->normalizeMoney((string) $data['unit_cost'])
            : null;
        $totalCost = $unitCost === null
            ? null
            : bcmul($quantity, $unitCost, 4);
        $idempotencyKey = $data['idempotency_key'] ?? null;

        return DB::transaction(function () use (
            $tenantId,
            $warehouse,
            $product,
            $movementType,
            $quantity,
            $unitCost,
            $totalCost,
            $idempotencyKey,
            $data,
        ): StockMovement {
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $existing = StockMovement::query()
                    ->forTenant($tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $movement = StockMovement::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'reference_type' => $data['reference_type'],
                'reference_ulid' => $data['reference_ulid'] ?? null,
                'reference_line_ulid' => $data['reference_line_ulid'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'posted_by' => $this->tenantContext->userId(),
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
            ]);

            $balance = StockBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = StockBalance::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => '0.000000',
                    'average_cost' => null,
                    'stock_value' => null,
                    'last_movement_id' => null,
                ]);
                $balance = StockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            }

            $oldQty = (string) $balance->quantity;
            $newQty = bcadd($oldQty, $quantity, 6);
            $updateAverage = (bool) ($data['update_average_cost'] ?? false);

            if ($updateAverage && $unitCost !== null && bccomp($quantity, '0', 6) === 1) {
                if (bccomp($oldQty, '0', 6) <= 0) {
                    $balance->average_cost = $unitCost;
                } else {
                    $oldValue = bcmul($oldQty, (string) ($balance->average_cost ?? '0'), 4);
                    $incomingValue = (string) $totalCost;
                    $combined = bcadd($oldValue, $incomingValue, 4);
                    $balance->average_cost = bcdiv($combined, $newQty, 4);
                }
            }

            $balance->quantity = $newQty;
            $avg = $balance->average_cost !== null ? (string) $balance->average_cost : null;
            $balance->stock_value = $avg === null
                ? null
                : bcmul($newQty, $avg, 4);
            $balance->last_movement_id = $movement->id;
            $balance->save();

            return $movement;
        });
    }

    private function normalizeQuantity(string $value): string
    {
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be a valid decimal with up to 6 places.',
            ]);
        }

        return bcadd($value, '0', 6);
    }

    private function normalizeMoney(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'unit_cost' => 'Unit cost must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
