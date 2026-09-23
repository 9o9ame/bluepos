<?php

namespace App\Actions\Purchases;

use App\Catalog\TenantCatalog;
use App\Enums\PurchaseReturnStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly RecalculatePurchaseReturnTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(PurchaseReturn $document, array $data): PurchaseReturn
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted purchase returns cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data): PurchaseReturn {
            $document = PurchaseReturn::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== PurchaseReturnStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted purchase returns cannot be edited.', 422);
            }

            if (array_key_exists('warehouse_ulid', $data)) {
                $warehouse = $this->catalog->warehouse((string) $data['warehouse_ulid']);
                if ($warehouse->status !== WarehouseStatus::Active) {
                    throw ValidationException::withMessages([
                        'warehouse_ulid' => 'Purchase returns cannot use an inactive warehouse.',
                    ]);
                }
                $document->warehouse_id = $warehouse->id;
                $document->branch_id = $warehouse->branch_id;
            }

            foreach (['return_date', 'supplier_reference', 'reason', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $document->{$field} = $data[$field];
                }
            }

            $document->updated_by = $this->tenantContext->userId();
            $document->save();
            $this->recalculate->execute($document);

            $this->audit->record('PURCHASE_RETURN_UPDATED', [
                'resource_type' => 'purchase_return',
                'resource_ulid' => $document->ulid,
            ]);

            return $document->fresh(CreatePurchaseReturnAction::with()) ?? $document;
        });
    }
}
