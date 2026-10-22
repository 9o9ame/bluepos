<?php

namespace App\Actions\Sales;

use App\Models\Sale;

class BuildSaleEffectiveSnapshotAction
{
    /**
     * Return the latest effective public snapshot without mutating the posted sale.
     *
     * @return array<string,mixed>
     */
    public function current(Sale $sale): array
    {
        $sale->loadMissing([
            'customer',
            'salesmanParty',
            'branch',
            'warehouse',
            'items.product',
            'items.unit',
            'items.saleScheme',
            'latestRevision',
        ]);

        $revision = $sale->latestRevision;
        if ($revision && is_array($revision->after_snapshot)) {
            return $revision->after_snapshot;
        }

        return [
            'ulid' => $sale->ulid,
            'document_number' => $sale->document_number,
            'status' => $sale->status->value,
            'sale_date' => $sale->sale_date?->toDateString(),
            'price_type' => $sale->price_type?->value,
            'subtotal' => (string) $sale->subtotal,
            'discount_amount' => (string) $sale->discount_amount,
            'invoice_discount_percent' => (string) $sale->invoice_discount_percent,
            'invoice_discount_amount' => (string) $sale->invoice_discount_amount,
            'tax_amount' => (string) $sale->tax_amount,
            'grand_total' => (string) $sale->grand_total,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'notes' => $sale->notes,
            'posted_at' => $sale->posted_at?->toIso8601String(),
            'customer' => $sale->customer ? [
                'ulid' => $sale->customer->ulid,
                'code' => $sale->customer->code,
                'name' => $sale->customer->name,
            ] : null,
            'salesman' => $sale->salesmanParty ? [
                'ulid' => $sale->salesmanParty->ulid,
                'code' => $sale->salesmanParty->code,
                'name' => $sale->salesmanParty->name,
                'address' => $sale->salesmanParty->address,
                'mobile' => $sale->salesmanParty->mobile ?: $sale->salesmanParty->phone,
            ] : null,
            'branch' => $sale->branch ? [
                'ulid' => $sale->branch->ulid,
                'code' => $sale->branch->code,
                'name' => $sale->branch->name,
            ] : null,
            'warehouse' => $sale->warehouse ? [
                'ulid' => $sale->warehouse->ulid,
                'code' => $sale->warehouse->code,
                'name' => $sale->warehouse->name,
            ] : null,
            'items' => $sale->items->map(fn ($item): array => [
                'ulid' => $item->ulid,
                'line_kind' => $item->line_kind->value,
                'quantity' => (string) $item->quantity,
                'stock_quantity' => (string) $item->stock_quantity,
                'barcode' => $item->barcode,
                'conversion_factor' => (string) $item->conversion_factor,
                'price_type' => $item->price_type?->value,
                'unit_price' => (string) $item->unit_price,
                'gross_amount' => (string) $item->gross_amount,
                'discount_percent' => (string) $item->discount_percent,
                'discount_amount' => (string) $item->discount_amount,
                'tax_percent' => (string) $item->tax_percent,
                'tax_amount' => (string) $item->tax_amount,
                'net_amount' => (string) $item->line_total,
                'line_total' => (string) $item->line_total,
                'notes' => $item->notes,
                'product' => $item->product ? [
                    'ulid' => $item->product->ulid,
                    'name' => $item->product->name,
                    'product_number' => $item->product->product_number,
                ] : null,
                'unit' => $item->unit ? [
                    'ulid' => $item->unit->ulid,
                    'code' => $item->unit->code,
                    'name' => $item->unit->name,
                    'symbol' => $item->unit->symbol,
                    'allows_decimal' => $item->unit->allows_decimal,
                ] : null,
                'sale_scheme' => $item->saleScheme ? [
                    'ulid' => $item->saleScheme->ulid,
                    'name' => $item->saleScheme->name,
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
     * @param array<string,mixed> $prepared
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function fromPrepared(
        Sale $sale,
        array $prepared,
        array $data,
        int $revisionNumber,
        ?string $amendmentJournalUlid,
        string $idempotencyKey,
    ): array {
        $sale->loadMissing(['customer', 'salesmanParty', 'branch', 'warehouse']);

        return [
            'ulid' => $sale->ulid,
            'document_number' => $sale->document_number,
            'status' => $sale->status->value,
            'sale_date' => $sale->sale_date?->toDateString(),
            'price_type' => $prepared['price_type']->value,
            'subtotal' => $prepared['subtotal'],
            'discount_amount' => $prepared['discount_amount'],
            'invoice_discount_percent' => $prepared['invoice_discount_percent'],
            'invoice_discount_amount' => $prepared['invoice_discount_amount'],
            'tax_amount' => $prepared['tax_amount'],
            'grand_total' => $prepared['grand_total'],
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'notes' => $data['notes'] ?? $sale->notes,
            'posted_at' => $sale->posted_at?->toIso8601String(),
            'customer' => $sale->customer ? [
                'ulid' => $sale->customer->ulid,
                'code' => $sale->customer->code,
                'name' => $sale->customer->name,
            ] : null,
            'salesman' => $sale->salesmanParty ? [
                'ulid' => $sale->salesmanParty->ulid,
                'code' => $sale->salesmanParty->code,
                'name' => $sale->salesmanParty->name,
                'address' => $sale->salesmanParty->address,
                'mobile' => $sale->salesmanParty->mobile ?: $sale->salesmanParty->phone,
            ] : null,
            'branch' => $sale->branch ? [
                'ulid' => $sale->branch->ulid,
                'code' => $sale->branch->code,
                'name' => $sale->branch->name,
            ] : null,
            'warehouse' => $sale->warehouse ? [
                'ulid' => $sale->warehouse->ulid,
                'code' => $sale->warehouse->code,
                'name' => $sale->warehouse->name,
            ] : null,
            'items' => collect($prepared['lines'])->values()->map(
                function (array $line, int $index) use ($revisionNumber): array {
                    $scheme = $line['sale_scheme_id'] ?? null;

                    return [
                        'ulid' => 'REV'.str_pad((string) $revisionNumber, 4, '0', STR_PAD_LEFT).'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                        'line_kind' => $line['line_kind'],
                        'quantity' => $line['quantity'],
                        'stock_quantity' => $line['stock_quantity'],
                        'barcode' => $line['barcode'] ?? null,
                        'conversion_factor' => $line['conversion_factor'],
                        'price_type' => $line['price_type'],
                        'unit_price' => $line['unit_price'],
                        'gross_amount' => $line['gross_amount'],
                        'discount_percent' => $line['discount_percent'],
                        'discount_amount' => $line['discount_amount'],
                        'tax_percent' => $line['tax_percent'],
                        'tax_amount' => $line['tax_amount'],
                        'net_amount' => $line['line_total'],
                        'line_total' => $line['line_total'],
                        'notes' => $line['notes'] ?? null,
                        'product' => [
                            'ulid' => $line['product']->ulid,
                            'name' => $line['product']->name,
                            'product_number' => $line['product']->product_number,
                        ],
                        'unit' => [
                            'ulid' => $line['unit']->ulid,
                            'code' => $line['unit']->code,
                            'name' => $line['unit']->name,
                            'symbol' => $line['unit']->symbol,
                            'allows_decimal' => $line['unit']->allows_decimal,
                        ],
                        'sale_scheme' => $scheme ? ['internal_reference' => (int) $scheme] : null,
                    ];
                }
            )->all(),
            '_meta' => [
                'revision_number' => $revisionNumber,
                'amendment_journal_ulid' => $amendmentJournalUlid,
                'idempotency_key' => $idempotencyKey,
            ],
        ];
    }
}
