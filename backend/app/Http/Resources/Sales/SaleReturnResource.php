<?php

namespace App\Http\Resources\Sales;

use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturn */
class SaleReturnResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $refundedAmount = '0.0000';

        if ($this->relationLoaded('refunds')) {
            foreach ($this->refunds as $refund) {
                $refundedAmount = bcadd($refundedAmount, (string) $refund->amount, 4);
            }
        } elseif ($this->getAttribute('refunded_amount') !== null) {
            $refundedAmount = bcadd((string) $this->getAttribute('refunded_amount'), '0', 4);
        }

        $refundBalance = bcsub((string) $this->grand_total, $refundedAmount, 4);
        if (bccomp($refundBalance, '0.0000', 4) < 0) {
            $refundBalance = '0.0000';
        }

        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'refund_amount' => $refundedAmount,
            'balance_due' => $refundBalance,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'original_sale' => $this->whenLoaded('sale', fn () => [
                'ulid' => $this->sale->ulid,
                'document_number' => $this->sale->document_number,
                'sale_date' => $this->sale->sale_date?->toDateString(),
                'grand_total' => $this->sale->grand_total,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'ulid' => $this->customer->ulid,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ] : null),
            'salesman' => $this->whenLoaded('salesmanParty', fn () => $this->salesmanParty ? [
                'ulid' => $this->salesmanParty->ulid,
                'code' => $this->salesmanParty->code,
                'name' => $this->salesmanParty->name,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'lines' => SaleReturnLineResource::collection($this->whenLoaded('lines')),
            'refunds' => SaleReturnRefundResource::collection($this->whenLoaded('refunds')),
        ];
    }
}
