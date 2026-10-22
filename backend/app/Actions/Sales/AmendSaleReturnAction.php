<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Enums\SaleReturnStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\SaleReturn;
use App\Models\SaleReturnRefund;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class AmendSaleReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildSaleReturnEffectiveSnapshotAction $snapshots,
        private readonly PrepareSaleReturnAmendmentAction $prepare,
        private readonly PostSaleReturnAmendmentStockDeltaAction $stockDelta,
        private readonly ReverseJournalEntryAction $reverseJournal,
        private readonly PostSaleReturnAccountingAction $postAccounting,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function execute(
        SaleReturn $document,
        array $data,
        string $idempotencyKey,
    ): SaleReturn {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to amend a posted sales return.',
                422,
            );
        }

        return DB::transaction(function () use (
            $document,
            $data,
            $idempotencyKey,
            $tenantId,
        ): SaleReturn {
            $existingRevision = DocumentRevision::query()
                ->forTenant($tenantId)
                ->where('document_type', 'sale_return')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRevision) {
                return SaleReturn::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $existingRevision->document_ulid)
                    ->with([...CreateSaleReturnAction::with(), 'latestRevision'])
                    ->firstOrFail();
            }

            $document = SaleReturn::query()
                ->forTenant($tenantId)
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($document->id)
                ->with([
                    ...CreateSaleReturnAction::with(),
                    'latestRevision',
                ])
                ->lockForUpdate()
                ->first();

            if (! $document) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($document->status !== SaleReturnStatus::Posted) {
                throw new ApiException(
                    'DOCUMENT_NOT_POSTED',
                    'Only posted Sales Returns can be amended.',
                    422,
                );
            }

            $before = $this->snapshots->current($document);
            $revisionNumber = ((int) ($before['_meta']['revision_number'] ?? 0)) + 1;

            $prepared = $this->prepare->execute($document, $data);

            $refunded = bcadd(
                (string) SaleReturnRefund::query()
                    ->forTenant($tenantId)
                    ->where('sale_return_id', $document->id)
                    ->sum('amount'),
                '0',
                4,
            );

            if (bccomp($prepared['totals']['grand_total'], $refunded, 4) < 0) {
                throw new ApiException(
                    'SALE_RETURN_AMENDMENT_BELOW_REFUNDED',
                    'The amended Sales Return total cannot be lower than the amount already refunded.',
                    422,
                );
            }

            $returnedCost = $this->stockDelta->execute(
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
                (string) ($data['reason'] ?? $before['reason'] ?? ''),
                $data['notes'] ?? ($before['notes'] ?? null),
                $revisionNumber,
                null,
                $idempotencyKey,
            );

            $amendmentJournal = $this->postAccounting->executeAmendment(
                $document,
                $provisional,
                $returnedCost,
                'sale-return-amend-accounting:'.$document->ulid.':'.$revisionNumber,
                $revisionNumber,
            );

            if ($previousJournal && ! $amendmentJournal) {
                throw new ApiException(
                    'SALE_RETURN_ACCOUNTING_CONFIGURATION_REQUIRED',
                    'The existing Sales Return has accounting entries, so accounting controls must remain configured before amendment.',
                    422,
                );
            }

            if ($previousJournal) {
                $this->reverseJournal->execute(
                    $previousJournal,
                    'sale-return-amend-reverse:'.$document->ulid.':'.$revisionNumber,
                    'Reverse previous effective posting for '.$document->document_number.' revision '.$revisionNumber,
                );
            }

            $after = $this->snapshots->fromPrepared(
                $document,
                $prepared['lines'],
                $prepared['totals'],
                (string) ($data['reason'] ?? $before['reason'] ?? ''),
                $data['notes'] ?? ($before['notes'] ?? null),
                $revisionNumber,
                $amendmentJournal?->ulid,
                $idempotencyKey,
            );

            $this->revisions->execute(
                'sale_return',
                $document->ulid,
                (string) $data['revision_reason'],
                $before,
                $after,
                (int) $document->branch_id,
                (int) $document->warehouse_id,
                $idempotencyKey,
            );

            $this->audit->record('SALE_RETURN_AMENDED', [
                'resource_type' => 'sale_return',
                'resource_ulid' => $document->ulid,
                'document_number' => $document->document_number,
                'revision_number' => $revisionNumber,
                'old_grand_total' => (string) ($before['grand_total'] ?? '0.0000'),
                'new_grand_total' => $prepared['totals']['grand_total'],
            ]);

            return $document->fresh([
                ...CreateSaleReturnAction::with(),
                'latestRevision',
            ]) ?? $document;
        });
    }

    /** @param array<string,mixed> $before */
    private function effectiveJournal(
        SaleReturn $document,
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
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->where('document_id', $document->id)
            ->first();
    }
}
