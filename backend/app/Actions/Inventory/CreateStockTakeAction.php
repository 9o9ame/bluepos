<?php

namespace App\Actions\Inventory;

use App\Catalog\TenantCatalog;
use App\Enums\StockTakeStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryStockTake;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStockTakeAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{warehouse_ulid: string, count_date?: string, notes?: string|null}  $data
     */
    public function execute(array $data): InventoryStockTake
    {
        return DB::transaction(function () use ($data): InventoryStockTake {
            $tenantId = $this->tenantContext->tenantId();
            $warehouse = $this->catalog->warehouse($data['warehouse_ulid']);

            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Stock taking cannot use an inactive warehouse.',
                ]);
            }

            $document = InventoryStockTake::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'count_date' => $data['count_date'] ?? now()->toDateString(),
                'status' => StockTakeStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('STOCK_TAKE_CREATED', [
                'resource_type' => 'inventory_stock_take',
                'resource_ulid' => $document->ulid,
            ]);

            return $document->fresh(['warehouse', 'lines.product']) ?? $document;
        });
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = InventoryStockTake::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/ST-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'ST-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
