<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\ApiException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DeletePurchaseInvoiceLineAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecalculatePurchaseTotalsAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PurchaseInvoice $invoice, PurchaseInvoiceLine $line): void
    {
        if (! $invoice->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted purchase invoices cannot be edited.', 422);
        }

        DB::transaction(function () use ($invoice, $line): void {
            $invoice = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== PurchaseInvoiceStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted purchase invoices cannot be edited.', 422);
            }

            $line = PurchaseInvoiceLine::query()
                ->whereKey($line->id)
                ->where('purchase_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lineUlid = $line->ulid;
            $line->delete();

            $invoice->updated_by = $this->tenantContext->userId();
            $invoice->save();
            $this->recalculate->execute($invoice);

            $this->audit->record('PURCHASE_LINE_REMOVED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
                'line_ulid' => $lineUlid,
            ]);
        });
    }
}
