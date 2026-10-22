<?php

namespace App\Actions\Purchases;

use App\Models\PurchaseInvoice;
use Illuminate\Support\Str;

class BuildPurchaseEffectiveSnapshotAction
{
    /**
     * @return array<string,mixed>
     */
    public function current(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing([
            'supplier',
            'branch',
            'warehouse',
            'purchaseOrder',
            'lines.product',
            'lines.unit',
            'lines.purchaseOrderLine',
            'latestRevision',
        ]);

        if ($invoice->latestRevision && is_array($invoice->latestRevision->after_snapshot)) {
            return $invoice->latestRevision->after_snapshot;
        }

        return [
            'ulid' => $invoice->ulid,
            'document_number' => $invoice->document_number,
            'supplier_invoice_number' => $invoice->supplier_invoice_number,
            'po_number' => $invoice->po_number,
            'invoice_type' => $invoice->invoice_type,
            'currency_code' => $invoice->currency_code,
            'calculation_method' => $invoice->calculation_method,
            'discount_apply_on' => $invoice->discount_apply_on,
            'calculation_modes' => $invoice->calculation_modes,
            'auto_calc_mrp' => (bool) $invoice->auto_calc_mrp,
            'withholding_is_pct' => (bool) $invoice->withholding_is_pct,
            'withholding_pct' => (string) $invoice->withholding_pct,
            'default_sales_tax_pct' => (string) $invoice->default_sales_tax_pct,
            'default_further_tax_pct' => (string) $invoice->default_further_tax_pct,
            'default_advance_tax_pct' => (string) $invoice->default_advance_tax_pct,
            'default_price_type' => $invoice->default_price_type,
            'brand_label' => $invoice->brand_label,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status->value,
            'subtotal' => (string) $invoice->subtotal,
            'discount_amount' => (string) $invoice->discount_amount,
            'tax_amount' => (string) $invoice->tax_amount,
            'further_tax_amount' => (string) $invoice->further_tax_amount,
            'freight_amount' => (string) $invoice->freight_amount,
            'loading_amount' => (string) $invoice->loading_amount,
            'other_charges' => (string) $invoice->other_charges,
            'other_discount' => (string) $invoice->other_discount,
            'trade_offer' => (string) $invoice->trade_offer,
            'advance_tax_amount' => (string) $invoice->advance_tax_amount,
            'round_off' => (string) $invoice->round_off,
            'grand_total' => (string) $invoice->grand_total,
            'notes' => $invoice->notes,
            'tax_type' => $invoice->tax_type,
            'payment_terms' => $invoice->payment_terms,
            'posted_at' => $invoice->posted_at?->toIso8601String(),
            'supplier' => $invoice->supplier ? [
                'ulid' => $invoice->supplier->ulid,
                'code' => $invoice->supplier->code,
                'name' => $invoice->supplier->name,
            ] : null,
            'branch' => $invoice->branch ? [
                'ulid' => $invoice->branch->ulid,
                'code' => $invoice->branch->code,
                'name' => $invoice->branch->name,
            ] : null,
            'warehouse' => $invoice->warehouse ? [
                'ulid' => $invoice->warehouse->ulid,
                'code' => $invoice->warehouse->code,
                'name' => $invoice->warehouse->name,
            ] : null,
            'purchase_order' => $invoice->purchaseOrder ? [
                'ulid' => $invoice->purchaseOrder->ulid,
                'document_number' => $invoice->purchaseOrder->document_number,
            ] : null,
            'lines' => $invoice->lines->map(fn ($line): array => [
                'ulid' => $line->ulid,
                'purchase_order_line_ulid' => $line->purchaseOrderLine?->ulid,
                'quantity' => (string) $line->quantity,
                'conversion_factor' => (string) $line->conversion_factor,
                'base_quantity' => (string) $line->base_quantity,
                'unit_cost' => (string) $line->unit_cost,
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
                'further_tax_amount' => (string) $line->further_tax_amount,
                'line_total' => (string) $line->line_total,
                'supplier_product_code' => $line->supplier_product_code,
                'batch_number' => $line->batch_number,
                'expiry_date' => $line->expiry_date?->toDateString(),
                'notes' => $line->notes,
                'brand_label' => $line->brand_label,
                'hs_code' => $line->hs_code,
                'pack_size' => $line->pack_size,
                'qty_ctn' => (string) $line->qty_ctn,
                'free_pcs' => (string) $line->free_pcs,
                'price_type' => $line->price_type,
                'mrp' => (string) $line->mrp,
                'trade_disc_pct' => (string) $line->trade_disc_pct,
                'regular_disc_pct' => (string) $line->regular_disc_pct,
                'regular_disc_amount' => (string) $line->regular_disc_amount,
                'special_disc_pct' => (string) $line->special_disc_pct,
                'special_disc_amount' => (string) $line->special_disc_amount,
                'disc_after_gst_pct' => (string) $line->disc_after_gst_pct,
                'disc_after_gst_amount' => (string) $line->disc_after_gst_amount,
                'tax_pct' => (string) $line->tax_pct,
                'further_tax_pct' => (string) $line->further_tax_pct,
                'product' => $line->product ? [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'sku' => $line->product->sku,
                    'name' => $line->product->name,
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
     * @param array<string,mixed> $prepared
     * @return array<string,mixed>
     */
    public function fromPrepared(
        PurchaseInvoice $invoice,
        array $prepared,
        int $revisionNumber,
        ?string $journalUlid,
        string $idempotencyKey,
    ): array {
        $before = $this->current($invoice);

        return [
            ...$before,
            ...$prepared['header'],
            'ulid' => $invoice->ulid,
            'document_number' => $invoice->document_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'status' => $invoice->status->value,
            'supplier' => $before['supplier'],
            'branch' => $before['branch'],
            'warehouse' => $before['warehouse'],
            'purchase_order' => $before['purchase_order'],
            'lines' => collect($prepared['lines'])->map(fn (array $line): array => [
                'ulid' => (string) Str::ulid(),
                'purchase_order_line_ulid' => null,
                'quantity' => $line['quantity'],
                'conversion_factor' => $line['conversion_factor'],
                'base_quantity' => $line['base_quantity'],
                'unit_cost' => $line['unit_cost'],
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'further_tax_amount' => $line['further_tax_amount'],
                'line_total' => $line['line_total'],
                'supplier_product_code' => $line['supplier_product_code'],
                'batch_number' => $line['batch_number'],
                'expiry_date' => $line['expiry_date'],
                'notes' => $line['notes'],
                'brand_label' => $line['brand_label'],
                'hs_code' => $line['hs_code'],
                'pack_size' => $line['pack_size'],
                'qty_ctn' => $line['qty_ctn'],
                'free_pcs' => $line['free_pcs'],
                'price_type' => $line['price_type'],
                'mrp' => $line['mrp'],
                'trade_disc_pct' => $line['trade_disc_pct'],
                'regular_disc_pct' => $line['regular_disc_pct'],
                'regular_disc_amount' => $line['regular_disc_amount'],
                'special_disc_pct' => $line['special_disc_pct'],
                'special_disc_amount' => $line['special_disc_amount'],
                'disc_after_gst_pct' => $line['disc_after_gst_pct'],
                'disc_after_gst_amount' => $line['disc_after_gst_amount'],
                'tax_pct' => $line['tax_pct'],
                'further_tax_pct' => $line['further_tax_pct'],
                'product' => [
                    'ulid' => $line['product']->ulid,
                    'product_number' => $line['product']->product_number,
                    'sku' => $line['product']->sku,
                    'name' => $line['product']->name,
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
