<?php

namespace App\Actions\Inventory;

use App\Catalog\TenantCatalog;
use App\Enums\OpeningBalanceStatus;
use App\Enums\ProductStatus;
use App\Exceptions\ApiException;
use App\Models\InventoryOpeningBalance;
use App\Models\InventoryOpeningBalanceLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertOpeningBalanceLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{product_ulid: string, quantity: string, unit_cost: string, notes?: string|null}  $data
     */
    public function execute(InventoryOpeningBalance $document, array $data, ?InventoryOpeningBalanceLine $line = null): InventoryOpeningBalanceLine
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data, $line): InventoryOpeningBalanceLine {
            $document = InventoryOpeningBalance::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== OpeningBalanceStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be edited.', 422);
            }

            $product = $this->catalog->product($data['product_ulid']);
            if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_ulid' => 'Opening stock cannot be posted for an inactive product.',
                ]);
            }

            $quantity = $this->positiveQuantity((string) $data['quantity']);
            $unitCost = $this->nonNegativeMoney((string) $data['unit_cost']);
            $totalCost = bcmul($quantity, $unitCost, 4);

            if ($line) {
                $line = InventoryOpeningBalanceLine::query()
                    ->whereKey($line->id)
                    ->where('opening_balance_id', $document->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $line->product_id = $product->id;
                $line->quantity = $quantity;
                $line->unit_cost = $unitCost;
                $line->total_cost = $totalCost;
                $line->notes = $data['notes'] ?? null;
                $line->save();
            } else {
                $existing = InventoryOpeningBalanceLine::query()
                    ->where('opening_balance_id', $document->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existing->quantity = $quantity;
                    $existing->unit_cost = $unitCost;
                    $existing->total_cost = $totalCost;
                    $existing->notes = $data['notes'] ?? null;
                    $existing->save();
                    $line = $existing;
                } else {
                    $line = InventoryOpeningBalanceLine::query()->create([
                        'tenant_id' => $this->tenantContext->tenantId(),
                        'opening_balance_id' => $document->id,
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'total_cost' => $totalCost,
                        'notes' => $data['notes'] ?? null,
                    ]);
                }
            }

            $this->audit->record('OPENING_BALANCE_UPDATED', [
                'resource_type' => 'inventory_opening_balance',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $line->ulid,
            ]);

            return $line->fresh(['product']) ?? $line;
        });
    }

    private function positiveQuantity(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Opening quantity must be greater than zero.',
            ]);
        }

        return bcadd($value, '0', 6);
    }

    private function nonNegativeMoney(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'unit_cost' => 'Unit cost must be a valid non-negative amount.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
