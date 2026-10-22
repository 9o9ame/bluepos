<?php

namespace App\Actions\Sales;

use App\Models\SaleReturn;
use Illuminate\Support\Str;

class BuildSaleReturnEffectiveSnapshotAction
{
    /**
     * @return array<string,mixed>
     */
    public function current(SaleReturn $document): array
    {
        $document->loadMissing([
            'sale',
            'customer',
            'settlementAccount',
            'salesmanParty',
            'branch',
            'warehouse',
            'lines.product.category',
            'lines.unit',
            'lines.saleItem',
            'refunds.account',
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
            'subtotal' => (string) $document->subtotal,
            'discount_amount' => (string) $document->discount_amount,
            'tax_amount' => (string) $document->tax_amount,
            'grand_total' => (string) $document->grand_total,
            'reason' => $document->reason,
            'notes' => $document->notes,
            'posted_at' => $document->posted_at?->toIso8601String(),
            'return_mode' => $document->sale_id === null ? 'general' : 'invoice',
            'original_sale' => $document->sale ? [
                'ulid' => $document->sale->ulid,
                'document_number' => $document->sale->document_number,
                'sale_date' => $document->sale->sale_date?->toDateString(),
                'grand_total' => (string) $document->sale->grand_total,
            ] : null,
            'customer' => $document->customer ? [
                'ulid' => $document->customer->ulid,
                'code' => $document->customer->code,
                'name' => $document->customer->name,
            ] : null,
            'settlement_account' => $document->settlementAccount ? [
                'ulid' => $document->settlementAccount->ulid,
                'code' => $document->settlementAccount->code,
                'name' => $document->settlementAccount->name,
            ] : null,
            'salesman' => $document->salesmanParty ? [
                'ulid' => $document->salesmanParty->ulid,
                'code' => $document->salesmanParty->code,
                'name' => $document->salesmanParty->name,
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
                'original_sale_item_ulid' => $line->saleItem?->ulid,
                'line_kind' => $line->line_kind->value,
                'barcode' => $line->barcode,
                'quantity' => (string) $line->quantity,
                'conversion_factor' => (string) $line->conversion_factor,
                'stock_quantity' => (string) $line->stock_quantity,
                'unit_price' => (string) $line->unit_price,
                'gross_amount' => (string) $line->gross_amount,
                'discount_percent' => (string) $line->discount_percent,
                'discount_amount' => (string) $line->discount_amount,
                'tax_percent' => (string) $line->tax_percent,
                'tax_amount' => (string) $line->tax_amount,
                'line_total' => (string) $line->line_total,
                'reason' => $line->reason,
                'notes' => $line->notes,
                'product' => $line->product ? [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'name' => $line->product->name,
                    'category' => $line->product->category ? [
                        'ulid' => $line->product->category->ulid,
                        'name' => $line->product->category->name,
                    ] : null,
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
        SaleReturn $document,
        array $lines,
        array $totals,
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
            'reason' => $reason,
            'notes' => $notes,
            'lines' => collect($lines)->map(fn (array $line): array => [
                'ulid' => (string) Str::ulid(),
                'original_sale_item_ulid' => $line['sale_item']?->ulid,
                'line_kind' => $line['line_kind'],
                'barcode' => $line['barcode'],
                'quantity' => $line['quantity'],
                'conversion_factor' => $line['conversion_factor'],
                'stock_quantity' => $line['stock_quantity'],
                'unit_price' => $line['unit_price'],
                'gross_amount' => $line['gross_amount'],
                'discount_percent' => $line['discount_percent'],
                'discount_amount' => $line['discount_amount'],
                'tax_percent' => $line['tax_percent'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
                'reason' => $line['reason'],
                'notes' => $line['notes'],
                'product' => [
                    'ulid' => $line['product']->ulid,
                    'product_number' => $line['product']->product_number,
                    'name' => $line['product']->name,
                    'category' => $line['product']->category ? [
                        'ulid' => $line['product']->category->ulid,
                        'name' => $line['product']->category->name,
                    ] : null,
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
