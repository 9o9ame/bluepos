<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryAdjustmentStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\InventoryAdjustment;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInventoryAdjustmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{account_ulid:string, adjustment_date?:string, adjustment_type?:string, notes?:string|null} $data
     */
    public function execute(array $data): InventoryAdjustment
    {
        return DB::transaction(function () use ($data): InventoryAdjustment {
            $tenantId = $this->tenantContext->tenantId();
            $warehouse = $this->tenantContext->warehouse();

            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Inventory adjustment cannot use an inactive warehouse.',
                ]);
            }

            $account = Account::query()
                ->forTenant($tenantId)
                ->where('ulid', $data['account_ulid'])
                ->where('is_active', true)
                ->first();

            if (! $account) {
                throw new ApiException('NOT_FOUND', 'The selected account was not found.', 404);
            }

            $document = InventoryAdjustment::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $this->tenantContext->branchId(),
                'warehouse_id' => $warehouse->id,
                'account_id' => $account->id,
                'document_number' => $this->nextDocumentNumber($tenantId),
                'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
                'adjustment_type' => $data['adjustment_type'] ?? 'stock_adjustment',
                'status' => InventoryAdjustmentStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('INVENTORY_ADJUSTMENT_CREATED', [
                'resource_type' => 'inventory_adjustment',
                'resource_ulid' => $document->ulid,
            ]);

            return $document->fresh(['warehouse', 'account', 'lines.product']) ?? $document;
        });
    }

    private function nextDocumentNumber(int $tenantId): string
    {
        $latest = InventoryAdjustment::query()
            ->forTenant($tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('document_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/ADJ-(\d+)$/', $latest, $matches) === 1) {
            $seq = (int) $matches[1] + 1;
        }

        return 'ADJ-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
