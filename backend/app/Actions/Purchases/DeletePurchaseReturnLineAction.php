<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseReturnStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DeletePurchaseReturnLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculatePurchaseReturnTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PurchaseReturn $document, PurchaseReturnLine $line): void
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted purchase returns cannot be edited.', 422);
        }

        DB::transaction(function () use ($document, $line): void {
            $document = PurchaseReturn::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== PurchaseReturnStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted purchase returns cannot be edited.', 422);
            }

            $line = PurchaseReturnLine::query()
                ->whereKey($line->id)
                ->where('purchase_return_id', $document->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lineUlid = $line->ulid;
            $line->delete();

            $document->updated_by = $this->tenantContext->userId();
            $document->save();
            $this->recalculate->execute($document);

            $this->audit->record('PURCHASE_RETURN_LINE_REMOVED', [
                'resource_type' => 'purchase_return',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $lineUlid,
            ]);
        });
    }
}
