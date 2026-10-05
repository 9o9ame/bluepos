<?php

namespace App\Actions\Sales;

use App\Enums\SaleReturnStatus;
use App\Exceptions\ApiException;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DeleteSaleReturnLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculateSaleReturnTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SaleReturn $document, SaleReturnLine $line): void
    {
        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted sales returns cannot be edited.', 422);
        }

        DB::transaction(function () use ($document, $line): void {
            $document = SaleReturn::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->status !== SaleReturnStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted sales returns cannot be edited.', 422);
            }

            $line = SaleReturnLine::query()
                ->whereKey($line->id)
                ->where('sale_return_id', $document->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lineUlid = $line->ulid;
            $line->delete();

            $document->updated_by = $this->tenantContext->userId();
            $document->save();
            $this->recalculate->execute($document);

            $this->audit->record('SALE_RETURN_LINE_REMOVED', [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
                'line_ulid' => $lineUlid,
            ]);
        });
    }
}
