<?php

namespace App\Actions\Purchases;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class AmendPurchaseInvoiceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildPurchaseEffectiveSnapshotAction $snapshots,
        private readonly PreparePurchaseAmendmentAction $prepare,
        private readonly PostPurchaseAmendmentStockDeltaAction $stockDelta,
        private readonly ReverseJournalEntryAction $reverseJournal,
        private readonly PostPurchaseAccountingAction $postAccounting,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(
        PurchaseInvoice $invoice,
        array $data,
        string $idempotencyKey,
    ): PurchaseInvoice {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to amend a posted purchase.',
                422,
            );
        }

        return DB::transaction(function () use (
            $invoice,
            $data,
            $idempotencyKey,
            $tenantId,
        ): PurchaseInvoice {
            $existingRevision = DocumentRevision::query()
                ->forTenant($tenantId)
                ->where('document_type', 'purchase_invoice')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRevision) {
                return PurchaseInvoice::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $existingRevision->document_ulid)
                    ->with([...CreatePurchaseInvoiceAction::with(), 'latestRevision'])
                    ->firstOrFail();
            }

            $invoice = PurchaseInvoice::query()
                ->forTenant($tenantId)
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($invoice->id)
                ->with([
                    ...CreatePurchaseInvoiceAction::with(),
                    'latestRevision',
                ])
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
                throw new ApiException(
                    'DOCUMENT_NOT_POSTED',
                    'Only posted Purchase Invoices can be amended.',
                    422,
                );
            }

            if ($invoice->purchase_order_id !== null) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_PO_LINKED',
                    'A Purchase Invoice linked to a Purchase Order cannot be amended until PO receipt quantities are revision-aware.',
                    409,
                );
            }

            $hasPostedReturn = PurchaseReturn::query()
                ->forTenant($tenantId)
                ->where('purchase_invoice_id', $invoice->id)
                ->where('status', PurchaseReturnStatus::Posted->value)
                ->exists();

            if ($hasPostedReturn) {
                throw new ApiException(
                    'PURCHASE_HAS_POSTED_RETURNS',
                    'This Purchase Invoice already has a posted Purchase Return. Resolve or amend the return before changing the original purchase.',
                    409,
                );
            }

            $before = $this->snapshots->current($invoice);
            $revisionNumber = ((int) ($before['_meta']['revision_number'] ?? 0)) + 1;

            $prepared = $this->prepare->execute($invoice, $data, $before);

            // Payment creation also locks this invoice row before inserting.
            $paidAmount = bcadd(
                (string) PurchasePayment::query()
                    ->forTenant($tenantId)
                    ->where('purchase_invoice_id', $invoice->id)
                    ->sum('amount'),
                '0',
                4,
            );

            if (bccomp((string) $prepared['header']['grand_total'], $paidAmount, 4) < 0) {
                throw new ApiException(
                    'PURCHASE_AMENDMENT_BELOW_PAID',
                    'The amended Purchase Invoice total cannot be lower than the amount already paid.',
                    422,
                );
            }

            $this->stockDelta->execute(
                $invoice,
                $before,
                $prepared['lines'],
                $revisionNumber,
            );

            $previousJournal = $this->effectiveJournal($invoice, $before);

            $provisional = $this->snapshots->fromPrepared(
                $invoice,
                $prepared,
                $revisionNumber,
                null,
                $idempotencyKey,
            );

            $amendmentJournal = $this->postAccounting->executeAmendment(
                $invoice,
                $provisional,
                'purchase-amend-accounting:'.$invoice->ulid.':'.$revisionNumber,
                $revisionNumber,
            );

            if ($previousJournal) {
                $this->reverseJournal->execute(
                    $previousJournal,
                    'purchase-amend-reverse:'.$invoice->ulid.':'.$revisionNumber,
                    'Reverse previous effective posting for '.$invoice->document_number.' revision '.$revisionNumber,
                );
            }

            $after = $this->snapshots->fromPrepared(
                $invoice,
                $prepared,
                $revisionNumber,
                $amendmentJournal?->ulid,
                $idempotencyKey,
            );

            $this->revisions->execute(
                'purchase_invoice',
                $invoice->ulid,
                (string) $data['revision_reason'],
                $before,
                $after,
                (int) $invoice->branch_id,
                (int) $invoice->warehouse_id,
                $idempotencyKey,
            );

            $this->audit->record('PURCHASE_AMENDED', [
                'resource_type' => 'purchase_invoice',
                'resource_ulid' => $invoice->ulid,
                'document_number' => $invoice->document_number,
                'revision_number' => $revisionNumber,
                'old_grand_total' => (string) ($before['grand_total'] ?? '0.0000'),
                'new_grand_total' => (string) $prepared['header']['grand_total'],
            ]);

            return $invoice->fresh([
                ...CreatePurchaseInvoiceAction::with(),
                'latestRevision',
            ]) ?? $invoice;
        });
    }

    /**
     * @param array<string,mixed> $before
     */
    private function effectiveJournal(
        PurchaseInvoice $invoice,
        array $before,
    ): ?JournalEntry {
        $journalUlid = $before['_meta']['amendment_journal_ulid'] ?? null;

        if (is_string($journalUlid) && $journalUlid !== '') {
            return JournalEntry::query()
                ->forTenant((int) $invoice->tenant_id)
                ->where('ulid', $journalUlid)
                ->first();
        }

        return JournalEntry::query()
            ->forTenant((int) $invoice->tenant_id)
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE)
            ->where('document_id', $invoice->id)
            ->first();
    }
}
