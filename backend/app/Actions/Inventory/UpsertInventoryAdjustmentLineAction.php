<?php

namespace App\Actions\Inventory;

use App\Catalog\TenantCatalog;
use App\Enums\InventoryAdjustmentStatus;
use App\Enums\ProductStatus;
use App\Exceptions\ApiException;
use App\Models\BusinessSetting;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\StockBalance;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertInventoryAdjustmentLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{product_ulid:string, qty_plus?:string|null, qty_minus?:string|null, notes?:string|null} $data
     */
    public function execute(
        InventoryAdjustment $document,
        array $data,
        ?InventoryAdjustmentLine $line = null,
    ): InventoryAdjustmentLine {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted inventory adjustments cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data, $line): InventoryAdjustmentLine {
            $document = InventoryAdjustment::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== InventoryAdjustmentStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted inventory adjustments cannot be edited.', 422);
            }

            $product = $this->catalog->product($data['product_ulid']);
            if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_ulid' => 'Inventory adjustment cannot use an inactive product.',
                ]);
            }

            $delta = $this->signedQuantity($data['qty_plus'] ?? null, $data['qty_minus'] ?? null);
            $balance = StockBalance::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->where('warehouse_id', $document->warehouse_id)
                ->where('product_id', $product->id)
                ->first();

            $system = bcadd((string) ($balance?->quantity ?? '0'), '0', 6);
            $after = bcadd($system, $delta, 6);

            if (
                bccomp($after, '0', 6) < 0
                && ! (bool) BusinessSetting::query()
                    ->forTenant($this->tenantContext->tenantId())
                    ->value('negative_stock_allowed')
            ) {
                throw ValidationException::withMessages([
                    'qty_minus' => 'Adjustment quantity cannot reduce stock below zero.',
                ]);
            }

            $unitCost = bcadd((string) ($balance?->average_cost ?? '0'), '0', 4);
            $absoluteQty = bccomp($delta, '0', 6) < 0 ? bcsub('0.000000', $delta, 6) : $delta;
            $amount = bcmul($absoluteQty, $unitCost, 4);

            if ($line) {
                $line = InventoryAdjustmentLine::query()
                    ->whereKey($line->id)
                    ->where('adjustment_id', $document->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $line = InventoryAdjustmentLine::query()
                    ->where('adjustment_id', $document->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();
            }

            if ($line) {
                $line->product_id = $product->id;
                $line->system_quantity = $system;
                $line->quantity_delta = $delta;
                $line->after_quantity = $after;
                $line->unit_cost = $unitCost;
                $line->total_amount = $amount;
                $line->notes = $data['notes'] ?? null;
                $line->save();
            } else {
                $line = InventoryAdjustmentLine::query()->create([
                    'tenant_id' => $this->tenantContext->tenantId(),
                    'adjustment_id' => $document->id,
                    'product_id' => $product->id,
                    'system_quantity' => $system,
                    'quantity_delta' => $delta,
                    'after_quantity' => $after,
                    'unit_cost' => $unitCost,
                    'total_amount' => $amount,
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            $this->audit->record('INVENTORY_ADJUSTMENT_UPDATED', [
                'resource_type' => 'inventory_adjustment',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $line->ulid,
            ]);

            return $line->fresh(['product']) ?? $line;
        });
    }

    private function signedQuantity(?string $plus, ?string $minus): string
    {
        $plus = $this->normalizeOptional($plus, 'qty_plus');
        $minus = $this->normalizeOptional($minus, 'qty_minus');

        $hasPlus = bccomp($plus, '0', 6) > 0;
        $hasMinus = bccomp($minus, '0', 6) > 0;

        if ($hasPlus === $hasMinus) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter either Qty + or Qty - with a value greater than zero.',
            ]);
        }

        return $hasPlus ? $plus : bcsub('0.000000', $minus, 6);
    }

    private function normalizeOptional(?string $value, string $field): string
    {
        $value = $value === null || $value === '' ? '0' : $value;

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Quantity must be a non-negative decimal with up to 6 places.',
            ]);
        }

        return bcadd($value, '0', 6);
    }
}
