<?php

namespace App\Actions\Inventory;

use App\Catalog\TenantCatalog;
use App\Enums\OpeningBalanceStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryOpeningBalance;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOpeningBalanceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{warehouse_ulid: string, document_date?: string, notes?: string|null}  $data
     */
    public function execute(array $data): InventoryOpeningBalance
    {
        return DB::transaction(function () use ($data): InventoryOpeningBalance {
            $tenantId = $this->tenantContext->tenantId();
            $warehouse = $this->catalog->warehouse($data['warehouse_ulid']);

            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse_ulid' => 'Opening stock cannot use an inactive warehouse.',
                ]);
            }

            $document = InventoryOpeningBalance::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'document_date' => $data['document_date'] ?? now()->toDateString(),
                'status' => OpeningBalanceStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('OPENING_BALANCE_CREATED', [
                'resource_type' => 'inventory_opening_balance',
                'resource_ulid' => $document->ulid,
            ]);

            return $document->fresh(['warehouse', 'lines.product']) ?? $document;
        });
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = InventoryOpeningBalance::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/OB-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'OB-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
