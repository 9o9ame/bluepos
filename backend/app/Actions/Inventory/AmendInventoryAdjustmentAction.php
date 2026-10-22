<?php

namespace App\Actions\Inventory;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Enums\InventoryAdjustmentStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentRevision;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class AmendInventoryAdjustmentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildInventoryAdjustmentEffectiveSnapshotAction $snapshots,
        private readonly PrepareInventoryAdjustmentAmendmentAction $prepare,
        private readonly PostInventoryAdjustmentAmendmentStockDeltaAction $stockDelta,
        private readonly PostInventoryAdjustmentAmendmentAccountingAction $postAccounting,
        private readonly ReverseJournalEntryAction $reverseJournal,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function execute(
        InventoryAdjustment $document,
        array $data,
        string $idempotencyKey,
    ): InventoryAdjustment {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to amend a posted inventory adjustment.',
                422,
            );
        }

        return DB::transaction(function () use (
            $document,
            $data,
            $idempotencyKey,
            $tenantId,
        ): InventoryAdjustment {
            $existingRevision = DocumentRevision::query()
                ->forTenant($tenantId)
                ->where('document_type', 'inventory_adjustment')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRevision) {
                return InventoryAdjustment::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $existingRevision->document_ulid)
                    ->with(['warehouse', 'account', 'lines.product', 'latestRevision'])
                    ->firstOrFail();
            }

            $document = InventoryAdjustment::query()
                ->forTenant($tenantId)
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($document->id)
                ->with(['warehouse', 'account', 'lines.product', 'latestRevision'])
                ->lockForUpdate()
                ->first();

            if (! $document) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($document->status !== InventoryAdjustmentStatus::Posted) {
                throw new ApiException(
                    'DOCUMENT_NOT_POSTED',
                    'Only posted inventory adjustments can be amended.',
                    422,
                );
            }

            $before = $this->snapshots->current($document);
            $revisionNumber = ((int) ($before['_meta']['revision_number'] ?? 0)) + 1;
            $prepared = $this->prepare->execute($document, $data, $before);

            $effectiveLines = $this->stockDelta->execute(
                $document,
                $before,
                $prepared['lines'],
                $revisionNumber,
            );

            $previousJournal = $this->effectiveJournal($document, $before);

            $provisional = $this->snapshots->fromPrepared(
                $document,
                $prepared['header'],
                $effectiveLines,
                $revisionNumber,
                null,
                $idempotencyKey,
            );

            $amendmentJournal = $this->postAccounting->execute(
                $document,
                $prepared['header'],
                $effectiveLines,
                'inventory-adjustment-amend-accounting:'.$document->ulid.':'.$revisionNumber,
                $revisionNumber,
            );

            if ($previousJournal) {
                $this->reverseJournal->execute(
                    $previousJournal,
                    'inventory-adjustment-amend-reverse:'.$document->ulid.':'.$revisionNumber,
                    'Reverse previous effective posting for '.$document->document_number.' revision '.$revisionNumber,
                );
            }

            $after = $this->snapshots->fromPrepared(
                $document,
                $prepared['header'],
                $effectiveLines,
                $revisionNumber,
                $amendmentJournal?->ulid,
                $idempotencyKey,
            );

            $this->revisions->execute(
                'inventory_adjustment',
                $document->ulid,
                (string) $data['revision_reason'],
                $before,
                $after,
                (int) $document->branch_id,
                (int) $document->warehouse_id,
                $idempotencyKey,
            );

            $this->audit->record('INVENTORY_ADJUSTMENT_AMENDED', [
                'resource_type' => 'inventory_adjustment',
                'resource_ulid' => $document->ulid,
                'document_number' => $document->document_number,
                'revision_number' => $revisionNumber,
                'old_net_amount' => (string) ($before['net_amount'] ?? '0.0000'),
                'new_net_amount' => (string) ($after['net_amount'] ?? '0.0000'),
            ]);

            return $document->fresh([
                'warehouse',
                'account',
                'lines.product',
                'latestRevision',
            ]) ?? $document;
        });
    }

    /** @param array<string,mixed> $before */
    private function effectiveJournal(
        InventoryAdjustment $document,
        array $before,
    ): ?JournalEntry {
        $journalUlid = $before['_meta']['amendment_journal_ulid'] ?? null;

        if (is_string($journalUlid) && $journalUlid !== '') {
            return JournalEntry::query()
                ->forTenant((int) $document->tenant_id)
                ->where('ulid', $journalUlid)
                ->first();
        }

        if ((int) ($before['_meta']['revision_number'] ?? 0) > 0) {
            return null;
        }

        return JournalEntry::query()
            ->forTenant((int) $document->tenant_id)
            ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT)
            ->where('document_id', $document->id)
            ->first();
    }
}
