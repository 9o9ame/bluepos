<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Documents\RecordDocumentRevisionAction;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AmendSaleAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateSaleAction $salePreparation,
        private readonly BuildSaleEffectiveSnapshotAction $snapshots,
        private readonly PostSaleAmendmentStockDeltaAction $stockDelta,
        private readonly ReverseJournalEntryAction $reverseJournal,
        private readonly PostSaleAccountingAction $postAccounting,
        private readonly RecordDocumentRevisionAction $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(
        Sale $sale,
        array $data,
        string $idempotencyKey,
    ): Sale {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to amend a posted sale.',
                422,
            );
        }

        return DB::transaction(function () use (
            $sale,
            $data,
            $idempotencyKey,
            $tenantId,
        ): Sale {
            $existingRevision = DocumentRevision::query()
                ->forTenant($tenantId)
                ->where('document_type', 'sale_invoice')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRevision) {
                return Sale::query()
                    ->forTenant($tenantId)
                    ->where('ulid', $existingRevision->document_ulid)
                    ->with([...CreateSaleAction::with(), 'latestRevision'])
                    ->firstOrFail();
            }

            $sale = Sale::query()
                ->forTenant($tenantId)
                ->where('branch_id', $this->tenantContext->branchId())
                ->where('warehouse_id', $this->tenantContext->warehouseId())
                ->whereKey($sale->id)
                ->with([
                    ...CreateSaleAction::with(),
                    'latestRevision',
                ])
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($sale->status !== SaleStatus::Posted) {
                throw new ApiException(
                    'DOCUMENT_NOT_POSTED',
                    'Only posted sales can be amended.',
                    422,
                );
            }

            $hasPostedReturn = SaleReturn::query()
                ->forTenant($tenantId)
                ->where('sale_id', $sale->id)
                ->where('status', SaleReturnStatus::Posted->value)
                ->lockForUpdate()
                ->exists();

            if ($hasPostedReturn) {
                throw new ApiException(
                    'SALE_HAS_POSTED_RETURNS',
                    'This invoice already has a posted Sales Return. Amend the return or resolve it before changing the original invoice.',
                    409,
                );
            }

            $before = $this->snapshots->current($sale);
            $currentRevision = (int) ($before['_meta']['revision_number'] ?? 0);
            $revisionNumber = $currentRevision + 1;

            $prepareData = $data;
            unset($prepareData['revision_reason']);
            $prepareData['warehouse_ulid'] = $sale->warehouse?->ulid;
            $prepareData['customer_ulid'] = $sale->customer?->ulid;
            $prepareData['salesman_ulid'] = $sale->salesmanParty?->ulid;
            $prepareData['sale_date'] = $sale->sale_date?->toDateString();
            $prepareData['items'] = $this->preservePriorLinePrices(
                $prepareData['items'] ?? [],
                $before['items'] ?? [],
            );

            $prepared = $this->salePreparation->prepareDocument($prepareData);

            $paidAmount = bcadd(
                (string) SalePayment::query()
                    ->forTenant($tenantId)
                    ->where('sale_id', $sale->id)
                    ->lockForUpdate()
                    ->sum('amount'),
                '0',
                4,
            );

            if (bccomp((string) $prepared['grand_total'], $paidAmount, 4) < 0) {
                throw new ApiException(
                    'SALE_AMENDMENT_BELOW_PAID',
                    'The amended invoice total cannot be lower than the amount already collected. Refund or adjust the payment first.',
                    422,
                );
            }

            $cogsAmount = $this->stockDelta->execute(
                $sale,
                $before,
                $prepared['lines'],
                $revisionNumber,
            );

            $previousJournal = $this->effectiveJournal($sale, $before);

            // Build a provisional snapshot so accounting uses the exact authoritative totals.
            $provisional = $this->snapshots->fromPrepared(
                $sale,
                $prepared,
                $prepareData,
                $revisionNumber,
                null,
                $idempotencyKey,
            );

            $amendmentJournal = $this->postAccounting->executeAmendment(
                $sale,
                $provisional,
                $cogsAmount,
                'sale-amend-accounting:'.$sale->ulid.':'.$revisionNumber,
                $revisionNumber,
            );

            if ($previousJournal && ! $amendmentJournal) {
                throw new ApiException(
                    'SALE_ACCOUNTING_CONFIGURATION_REQUIRED',
                    'The original invoice has accounting entries, so accounting controls must remain configured before it can be amended.',
                    422,
                );
            }

            if ($previousJournal) {
                $this->reverseJournal->execute(
                    $previousJournal,
                    'sale-amend-reverse:'.$sale->ulid.':'.$revisionNumber,
                    'Reverse previous effective posting for '.$sale->document_number.' revision '.$revisionNumber,
                );
            }

            $after = $this->snapshots->fromPrepared(
                $sale,
                $prepared,
                $prepareData,
                $revisionNumber,
                $amendmentJournal?->ulid,
                $idempotencyKey,
            );

            $this->revisions->execute(
                'sale_invoice',
                $sale->ulid,
                (string) $data['revision_reason'],
                $before,
                $after,
                (int) $sale->branch_id,
                (int) $sale->warehouse_id,
                $idempotencyKey,
            );

            $this->audit->record('SALE_AMENDED', [
                'resource_type' => 'sale',
                'resource_ulid' => $sale->ulid,
                'document_number' => $sale->document_number,
                'revision_number' => $revisionNumber,
                'old_grand_total' => (string) ($before['grand_total'] ?? '0.0000'),
                'new_grand_total' => (string) $prepared['grand_total'],
            ]);

            return $sale->fresh([
                ...CreateSaleAction::with(),
                'latestRevision',
            ]) ?? $sale;
        });
    }

    /**
     * Preserve the prior effective price when a line is unchanged by identity.
     * A genuinely changed client override still flows through sales.override_price.
     *
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $beforeItems
     * @return list<array<string,mixed>>
     */
    private function preservePriorLinePrices(array $items, array $beforeItems): array
    {
        $queues = [];

        foreach ($beforeItems as $beforeItem) {
            if (($beforeItem['line_kind'] ?? 'sale') !== 'sale') {
                continue;
            }

            $key = $this->lineIdentityKey($beforeItem);
            $queues[$key] ??= [];
            $queues[$key][] = $beforeItem;
        }

        foreach ($items as $index => $item) {
            $key = $this->lineIdentityKey($item);
            $prior = null;

            if (! empty($queues[$key])) {
                $prior = array_shift($queues[$key]);
            }

            if (! is_array($prior)) {
                continue;
            }

            $requestedOverride = trim((string) ($item['unit_price_override'] ?? ''));
            $priorPrice = bcadd((string) ($prior['unit_price'] ?? '0'), '0', 4);

            if (
                $requestedOverride === ''
                || bccomp($requestedOverride, $priorPrice, 4) === 0
            ) {
                unset($items[$index]['unit_price_override']);
                $items[$index]['_trusted_prior_unit_price'] = $priorPrice;
            }
        }

        return array_values($items);
    }

    /**
     * @param array<string,mixed> $line
     */
    private function lineIdentityKey(array $line): string
    {
        return implode('|', [
            (string) ($line['product_ulid'] ?? ($line['product']['ulid'] ?? '')),
            (string) ($line['unit_ulid'] ?? ($line['unit']['ulid'] ?? '')),
            (string) ($line['barcode'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed> $before
     */
    private function effectiveJournal(Sale $sale, array $before): ?JournalEntry
    {
        $amendmentJournalUlid = $before['_meta']['amendment_journal_ulid'] ?? null;

        if (is_string($amendmentJournalUlid) && $amendmentJournalUlid !== '') {
            return JournalEntry::query()
                ->forTenant((int) $sale->tenant_id)
                ->where('ulid', $amendmentJournalUlid)
                ->first();
        }

        return JournalEntry::query()
            ->forTenant((int) $sale->tenant_id)
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->first();
    }
}
