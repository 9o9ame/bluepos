<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAdjustment;

class BuildInventoryAdjustmentEffectiveSnapshotAction
{
    /** @return array<string,mixed> */
    public function current(InventoryAdjustment $document): array
    {
        $document->loadMissing([
            'warehouse',
            'account',
            'lines.product',
            'latestRevision',
        ]);

        if ($document->latestRevision && is_array($document->latestRevision->after_snapshot)) {
            return $document->latestRevision->after_snapshot;
        }

        $net = '0.0000';
        $lines = $document->lines->map(function ($line) use (&$net): array {
            $delta = bcadd((string) $line->quantity_delta, '0', 6);
            $amount = bcadd((string) $line->total_amount, '0', 4);
            $net = bccomp($delta, '0', 6) > 0
                ? bcadd($net, $amount, 4)
                : bcsub($net, $amount, 4);

            return [
                'ulid' => $line->ulid,
                'in_stock' => (string) $line->system_quantity,
                'qty_plus' => bccomp($delta, '0', 6) > 0 ? $delta : '0.000000',
                'qty_minus' => bccomp($delta, '0', 6) < 0 ? bcsub('0.000000', $delta, 6) : '0.000000',
                'quantity_delta' => $delta,
                'after_stock' => (string) $line->after_quantity,
                'price' => (string) $line->unit_cost,
                'amount' => $amount,
                'remarks' => $line->notes,
                'product' => $line->product ? [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'sku' => $line->product->sku,
                    'name' => $line->product->name,
                ] : null,
            ];
        })->values()->all();

        return [
            'ulid' => $document->ulid,
            'document_number' => $document->document_number,
            'adjustment_date' => $document->adjustment_date?->toDateString(),
            'adjustment_type' => $document->adjustment_type,
            'status' => $document->status->value,
            'notes' => $document->notes,
            'net_amount' => $net,
            'posted_at' => $document->posted_at?->toIso8601String(),
            'warehouse' => $document->warehouse ? [
                'ulid' => $document->warehouse->ulid,
                'code' => $document->warehouse->code,
                'name' => $document->warehouse->name,
            ] : null,
            'account' => $document->account ? [
                'ulid' => $document->account->ulid,
                'code' => $document->account->code,
                'name' => $document->account->name,
            ] : null,
            'lines' => $lines,
            '_meta' => [
                'revision_number' => 0,
                'amendment_journal_ulid' => null,
                'idempotency_key' => null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public function fromPrepared(
        InventoryAdjustment $document,
        array $header,
        array $lines,
        int $revisionNumber,
        ?string $journalUlid,
        string $idempotencyKey,
    ): array {
        $before = $this->current($document);
        $net = '0.0000';

        foreach ($lines as $line) {
            $net = bccomp((string) $line['quantity_delta'], '0', 6) > 0
                ? bcadd($net, (string) $line['amount'], 4)
                : bcsub($net, (string) $line['amount'], 4);
        }

        return [
            ...$before,
            'adjustment_date' => $header['adjustment_date'],
            'notes' => $header['notes'],
            'account' => $header['account'],
            'warehouse' => $header['warehouse'],
            'net_amount' => $net,
            'lines' => $lines,
            '_meta' => [
                'revision_number' => $revisionNumber,
                'amendment_journal_ulid' => $journalUlid,
                'idempotency_key' => $idempotencyKey,
            ],
        ];
    }
}
