<?php

namespace App\Actions\Inventory;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Enums\StockTakeStatus;
use App\Exceptions\ApiException;
use App\Models\InventoryStockTake;
use App\Models\InventoryStockTakeLine;
use App\Models\StockBalance;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertStockTakeLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{product_ulid: string, counted_quantity: string, notes?: string|null}  $data
     */
    public function execute(
        InventoryStockTake $document,
        array $data,
        ?InventoryStockTakeLine $line = null,
    ): InventoryStockTakeLine {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted stock takes cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data, $line): InventoryStockTakeLine {
            $document = InventoryStockTake::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== StockTakeStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted stock takes cannot be edited.', 422);
            }

            $product = $this->catalog->product($data['product_ulid']);
            if ($product->status !== ProductStatus::Active || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_ulid' => 'Stock taking cannot use an inactive product.',
                ]);
            }

            $counted = $this->nonNegativeQuantity((string) $data['counted_quantity']);
            $system = (string) (StockBalance::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->where('warehouse_id', $document->warehouse_id)
                ->where('product_id', $product->id)
                ->value('quantity') ?? '0.000000');
            $system = bcadd($system, '0', 6);
            $variance = bcsub($counted, $system, 6);

            if ($line) {
                $line = InventoryStockTakeLine::query()
                    ->whereKey($line->id)
                    ->where('stock_take_id', $document->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $line->product_id = $product->id;
                $line->system_quantity = $system;
                $line->counted_quantity = $counted;
                $line->variance_quantity = $variance;
                $line->notes = $data['notes'] ?? null;
                $line->save();
            } else {
                $line = InventoryStockTakeLine::query()
                    ->where('stock_take_id', $document->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if ($line) {
                    $line->system_quantity = $system;
                    $line->counted_quantity = $counted;
                    $line->variance_quantity = $variance;
                    $line->notes = $data['notes'] ?? null;
                    $line->save();
                } else {
                    $line = InventoryStockTakeLine::query()->create([
                        'tenant_id' => $this->tenantContext->tenantId(),
                        'stock_take_id' => $document->id,
                        'product_id' => $product->id,
                        'system_quantity' => $system,
                        'counted_quantity' => $counted,
                        'variance_quantity' => $variance,
                        'notes' => $data['notes'] ?? null,
                    ]);
                }
            }

            $this->audit->record('STOCK_TAKE_UPDATED', [
                'resource_type' => 'inventory_stock_take',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $line->ulid,
            ]);

            return $line->fresh(['product']) ?? $line;
        });
    }

    private function nonNegativeQuantity(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                'counted_quantity' => 'Counted quantity must be a valid non-negative decimal with up to 6 places.',
            ]);
        }

        return bcadd($value, '0', 6);
    }
}
