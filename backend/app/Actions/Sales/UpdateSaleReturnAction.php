<?php

namespace App\Actions\Sales;

use App\Enums\SaleReturnStatus;
use App\Exceptions\ApiException;
use App\Models\SaleReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class UpdateSaleReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculateSaleReturnTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function execute(SaleReturn $document, array $data): SaleReturn
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted sales returns cannot be edited.', 422);
        }

        return DB::transaction(function () use ($document, $data): SaleReturn {
            $document = SaleReturn::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->status !== SaleReturnStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted sales returns cannot be edited.', 422);
            }

            foreach (['return_date', 'reason', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $document->{$field} = $data[$field];
                }
            }

            $document->updated_by = $this->tenantContext->userId();
            $document->save();
            $this->recalculate->execute($document);

            $this->audit->record('SALE_RETURN_UPDATED', [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
            ]);

            return $document->fresh(CreateSaleReturnAction::with()) ?? $document;
        });
    }
}
