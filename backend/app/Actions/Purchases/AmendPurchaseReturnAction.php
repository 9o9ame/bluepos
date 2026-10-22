<?php

namespace App\Actions\Purchases;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\PurchaseReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class AmendPurchaseReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildPurchaseReturnEffectiveSnapshotAction $snapshots,
        private readonly PreparePurchaseReturnAmendmentAction $prepare,
        private readonly PostPurchaseReturnAmendmentStockDeltaAction $stockDelta,
        private readonly ReverseJournalEntryAction $reverseJournal,
        private readonly PostPurchaseReturnAccountingAction $postAccounting,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function execute(
        PurchaseReturn $document,
        array $data,
        string $idempotencyKey,
    ): PurchaseReturn {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to amend a posted Purchase Return.',
                422,
            );
        }

        return DB::transaction(function () use (
            $document,
            $data,
            $idempotencyKey,
            $tenantId,
        ): PurchaseReturn {
            $existingRevision = DocumentRevision::query()
                ->forTenant($tenantId)
                ->where('document_type', 'purchase_return')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRevision) {
                return PurchaseReturn::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $existingRevision->document_ulid)
                    ->with([...CreatePurchaseReturnAction::with(), 'latestRevision'])
                    ->firstOrFail();
            }

            $document = PurchaseReturn::query()
                ->forTenant($tenantId)
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($document->id)
                ->with([
                    ...CreatePurchaseReturnAction::with(),
                    'latestRevision',
                ])
                ->lockForUpdate()
                ->first();

            if (! $document) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($document->status !== PurchaseReturnStatus::Posted) {
                throw new ApiException(
                    'DOCUMENT_NOT_POSTED',
                    'Only posted Purchase Returns can be amended.',
                    422,
                );
            }

            $before = $this->snapshots->current($document);
            $revisionNumber = ((int) ($before['_meta']['revision_number'] ?? 0)) + 1;

            $prepared = $this->prepare->execute($document, $data);

            $this->stockDelta->execute(
                $document,
                $before,
                $prepared['lines'],
                $revisionNumber,
            );

            $previousJournal = $this->effectiveJournal($document, $before);

            $provisional = $this->snapshots->fromPrepared(
                $document,
                $prepared['lines'],
                $prepared['totals'],
                (string) ($data['supplier_reference'] ?? $before['supplier_reference'] ?? ''),
                (string) ($data['reason'] ?? $before['reason'] ?? ''),
                $data['notes'] ?? ($before['notes'] ?? null),
                $revisionNumber,
                null,
                $idempotencyKey,
            );

            $amendmentJournal = $this->postAccounting->executeAmendment(
                $document,
                $provisional,
                'purchase-return-amend-accounting:'.$document->ulid.':'.$revisionNumber,
                $revisionNumber,
            );

            if ($previousJournal) {
                $this->reverseJournal->execute(
                    $previousJournal,
                    'purchase-return-amend-reverse:'.$document->ulid.':'.$revisionNumber,
                    'Reverse previous effective posting for '.$document->document_number.' revision '.$revisionNumber,
                );
            }

            $after = $this->snapshots->fromPrepared(
                $document,
                $prepared['lines'],
                $prepared['totals'],
                (string) ($data['supplier_reference'] ?? $before['supplier_reference'] ?? ''),
                (string) ($data['reason'] ?? $before['reason'] ?? ''),
                $data['notes'] ?? ($before['notes'] ?? null),
                $revisionNumber,
                $amendmentJournal?->ulid,
                $idempotencyKey,
            );

            $this->revisions->execute(
                'purchase_return',
                $document->ulid,
                (string) $data['revision_reason'],
                $before,
                $after,
                (int) $document->branch_id,
                (int) $document->warehouse_id,
                $idempotencyKey,
            );

            $this->audit->record('PURCHASE_RETURN_AMENDED', [
                'resource_type' => 'purchase_return',
                'resource_ulid' => $document->ulid,
                'document_number' => $document->document_number,
                'revision_number' => $revisionNumber,
                'old_grand_total' => (string) ($before['grand_total'] ?? '0.0000'),
                'new_grand_total' => $prepared['totals']['grand_total'],
            ]);

            return $document->fresh([
                ...CreatePurchaseReturnAction::with(),
                'latestRevision',
            ]) ?? $document;
        });
    }

    /** @param array<string,mixed> $before */
    private function effectiveJournal(
        PurchaseReturn $document,
        array $before,
    ): ?JournalEntry {
        $journalUlid = $before['_meta']['amendment_journal_ulid'] ?? null;

        if (is_string($journalUlid) && $journalUlid !== '') {
            return JournalEntry::query()
                ->forTenant((int) $document->tenant_id)
                ->where('ulid', $journalUlid)
                ->first();
        }

        return JournalEntry::query()
            ->forTenant((int) $document->tenant_id)
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_RETURN)
            ->where('document_id', $document->id)
            ->first();
    }
}
