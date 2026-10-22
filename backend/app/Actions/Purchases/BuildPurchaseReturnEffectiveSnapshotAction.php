<?php

namespace App\Actions\Purchases;

use App\Models\PurchaseReturn;
use Illuminate\Support\Str;

class BuildPurchaseReturnEffectiveSnapshotAction
{
    /** @return array<string,mixed> */
    public function current(PurchaseReturn $document): array
    {
        $document->loadMissing([
            'supplier',
            'branch',
            'warehouse',
            'purchaseInvoice',
            'lines.product',
            'lines.unit',
            'lines.purchaseInvoiceLine',
            'latestRevision',
        ]);

        if ($document->latestRevision && is_array($document->latestRevision->after_snapshot)) {
            return $document->latestRevision->after_snapshot;
        }

        return [
            'ulid' => $document->ulid,
            'document_number' => $document->document_number,
            'return_date' => $document->return_date?->toDateString(),
            'status' => $document->status->value,
            'return_mode' => $document->purchase_invoice_id === null ? 'general' : 'invoice',
            'subtotal' => (string) $document->subtotal,
            'discount_amount' => (string) $document->discount_amount,
            'tax_amount' => (string) $document->tax_amount,
            'grand_total' => (string) $document->grand_total,
            'supplier_reference' => $document->supplier_reference,
            'reason' => $document->reason,
            'notes' => $document->notes,
            'posted_at' => $document->posted_at?->toIso8601String(),
            'original_purchase' => $document->purchaseInvoice ? [
                'ulid' => $document->purchaseInvoice->ulid,
                'document_number' => $document->purchaseInvoice->document_number,
                'supplier_invoice_number' => $document->purchaseInvoice->supplier_invoice_number,
            ] : null,
            'supplier' => $document->supplier ? [
                'ulid' => $document->supplier->ulid,
                'code' => $document->supplier->code,
                'name' => $document->supplier->name,
            ] : null,
            'branch' => $document->branch ? [
                'ulid' => $document->branch->ulid,
                'code' => $document->branch->code,
                'name' => $document->branch->name,
            ] : null,
            'warehouse' => $document->warehouse ? [
                'ulid' => $document->warehouse->ulid,
                'code' => $document->warehouse->code,
                'name' => $document->warehouse->name,
            ] : null,
            'lines' => $document->lines->map(fn ($line): array => [
                'ulid' => $line->ulid,
                'original_purchase_line_ulid' => $line->purchaseInvoiceLine?->ulid,
                'quantity' => (string) $line->quantity,
                'conversion_factor' => (string) $line->conversion_factor,
                'base_quantity' => (string) $line->base_quantity,
                'unit_cost' => (string) $line->unit_cost,
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
                'line_total' => (string) $line->line_total,
                'batch_number' => $line->batch_number,
                'expiry_date' => $line->expiry_date?->toDateString(),
                'reason' => $line->reason,
                'notes' => $line->notes,
                'product' => $line->product ? [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'sku' => $line->product->sku,
                    'name' => $line->product->name,
                    'track_batch' => (bool) $line->product->track_batch,
                    'track_expiry' => (bool) $line->product->track_expiry,
                ] : null,
                'unit' => $line->unit ? [
                    'ulid' => $line->unit->ulid,
                    'code' => $line->unit->code,
                    'name' => $line->unit->name,
                ] : null,
            ])->values()->all(),
            '_meta' => [
                'revision_number' => 0,
                'amendment_journal_ulid' => null,
                'idempotency_key' => null,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array{subtotal:string,discount_amount:string,tax_amount:string,grand_total:string} $totals
     * @return array<string,mixed>
     */
    public function fromPrepared(
        PurchaseReturn $document,
        array $lines,
        array $totals,
        string $supplierReference,
        string $reason,
        ?string $notes,
        int $revisionNumber,
        ?string $journalUlid,
        string $idempotencyKey,
    ): array {
        $before = $this->current($document);

        return [
            ...$before,
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'tax_amount' => $totals['tax_amount'],
            'grand_total' => $totals['grand_total'],
            'supplier_reference' => $supplierReference,
            'reason' => $reason,
            'notes' => $notes,
            'lines' => collect($lines)->map(fn (array $line): array => [
                'ulid' => (string) Str::ulid(),
                'original_purchase_line_ulid' => $line['purchase_line']?->ulid,
                'quantity' => $line['quantity'],
                'conversion_factor' => $line['conversion_factor'],
                'base_quantity' => $line['base_quantity'],
                'unit_cost' => $line['unit_cost'],
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
                'batch_number' => $line['batch_number'],
                'expiry_date' => $line['expiry_date'],
                'reason' => $line['reason'],
                'notes' => $line['notes'],
                'product' => [
                    'ulid' => $line['product']->ulid,
                    'product_number' => $line['product']->product_number,
                    'sku' => $line['product']->sku,
                    'name' => $line['product']->name,
                    'track_batch' => (bool) $line['product']->track_batch,
                    'track_expiry' => (bool) $line['product']->track_expiry,
                ],
                'unit' => [
                    'ulid' => $line['unit']->ulid,
                    'code' => $line['unit']->code,
                    'name' => $line['unit']->name,
                ],
            ])->values()->all(),
            '_meta' => [
                'revision_number' => $revisionNumber,
                'amendment_journal_ulid' => $journalUlid,
                'idempotency_key' => $idempotencyKey,
            ],
        ];
    }
}
