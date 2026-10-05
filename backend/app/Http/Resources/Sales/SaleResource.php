<?php

namespace App\Http\Resources\Sales;

use App\Enums\SaleReturnStatus;
use App\Models\SaleReturn;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
class SaleResource extends JsonResource
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
        }

        $returnedAmount = '0.0000';

        if ($this->getAttribute('returned_amount') !== null) {
            $returnedAmount = bcadd((string) $this->getAttribute('returned_amount'), '0', 4);
        } else {
            $returnedAmount = bcadd(
                (string) SaleReturn::query()
                    ->where('tenant_id', (int) $this->tenant_id)
                    ->where('sale_id', $this->id)
                    ->where('status', SaleReturnStatus::Posted->value)
                    ->sum('grand_total'),
                '0',
                4,
            );
        }

        $netSaleTotal = bcsub((string) $this->grand_total, $returnedAmount, 4);
        if (bccomp($netSaleTotal, '0.0000', 4) < 0) {
            $netSaleTotal = '0.0000';
        }

        $balanceDue = bcsub($netSaleTotal, $paidAmount, 4);
        if (bccomp($balanceDue, '0.0000', 4) < 0) {
            $balanceDue = '0.0000';
        }

        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'status' => $this->status->value,
            'sale_date' => $this->sale_date?->toDateString(),
            'price_type' => $this->price_type?->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'returned_amount' => $returnedAmount,
            'net_sale_total' => $netSaleTotal,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'salesman' => $this->whenLoaded('salesmanParty', fn () => $this->salesmanParty === null ? null : [
                'ulid' => $this->salesmanParty->ulid,
                'code' => $this->salesmanParty->code,
                'name' => $this->salesmanParty->name,
                'address' => $this->salesmanParty->address,
                'mobile' => $this->salesmanParty->mobile ?: $this->salesmanParty->phone,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'ulid' => $this->customer->ulid,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ]),
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
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
