<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchaseInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseInvoice
 */
class PurchaseInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paidAmount = '0.0000';

        if ($this->relationLoaded('payments')) {
            foreach ($this->payments as $payment) {
                $paidAmount = bcadd($paidAmount, (string) $payment->amount, 4);
            }
        } elseif ($this->getAttribute('paid_amount') !== null) {
            $paidAmount = bcadd((string) $this->getAttribute('paid_amount'), '0', 4);
        } else {
            $paidAmount = bcadd(
                (string) $this->payments()->sum('amount'),
                '0',
                4,
            );
        }

        $balancePayable = bcsub((string) $this->grand_total, $paidAmount, 4);
        if (bccomp($balancePayable, '0.0000', 4) < 0) {
            $balancePayable = '0.0000';
        }

        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'po_number' => $this->po_number,
            'invoice_type' => $this->invoice_type,
            'currency_code' => $this->currency_code,
            'calculation_method' => $this->calculation_method,
            'default_sales_tax_pct' => $this->default_sales_tax_pct,
            'default_further_tax_pct' => $this->default_further_tax_pct,
            'default_advance_tax_pct' => $this->default_advance_tax_pct,
            'default_price_type' => $this->default_price_type,
            'brand_label' => $this->brand_label,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'further_tax_amount' => $this->further_tax_amount,
            'freight_amount' => $this->freight_amount,
            'loading_amount' => $this->loading_amount,
            'other_charges' => $this->other_charges,
            'other_discount' => $this->other_discount,
            'trade_offer' => $this->trade_offer,
            'advance_tax_amount' => $this->advance_tax_amount,
            'round_off' => $this->round_off,
            'grand_total' => $this->grand_total,
            'paid_amount' => $paidAmount,
            'balance_payable' => $balancePayable,
            'notes' => $this->notes,
            'tax_type' => $this->tax_type,
            'payment_terms' => $this->payment_terms,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'supplier' => $this->whenLoaded('supplier', function () {
                return [
                    'ulid' => $this->supplier->ulid,
                    'code' => $this->supplier->code,
                    'name' => $this->supplier->name,
                ];
            }),
            'branch' => $this->whenLoaded('branch', function () {
                return [
                    'ulid' => $this->branch->ulid,
                    'code' => $this->branch->code,
                    'name' => $this->branch->name,
                ];
            }),
            'warehouse' => $this->whenLoaded('warehouse', function () {
                return [
                    'ulid' => $this->warehouse->ulid,
                    'code' => $this->warehouse->code,
                    'name' => $this->warehouse->name,
                ];
            }),
            'lines' => PurchaseInvoiceLineResource::collection($this->whenLoaded('lines')),
            'payments' => PurchasePaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
