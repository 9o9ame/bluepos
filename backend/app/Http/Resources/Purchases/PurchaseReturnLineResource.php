<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchaseReturnLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseReturnLine
 */
class PurchaseReturnLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'original_purchase_line_ulid' => $this->whenLoaded(
                'purchaseInvoiceLine',
                fn () => $this->purchaseInvoiceLine?->ulid,
            ),
            'quantity' => $this->quantity,
            'conversion_factor' => $this->conversion_factor,
            'base_quantity' => $this->base_quantity,
            'unit_cost' => $this->unit_cost,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'product' => $this->whenLoaded('product', function () {
                return [
                    'ulid' => $this->product->ulid,
                    'product_number' => $this->product->product_number,
                    'sku' => $this->product->sku,
                    'name' => $this->product->name,
                    'track_batch' => (bool) $this->product->track_batch,
                    'track_expiry' => (bool) $this->product->track_expiry,
                ];
            }),
            'unit' => $this->whenLoaded('unit', function () {
                return [
                    'ulid' => $this->unit->ulid,
                    'code' => $this->unit->code,
                    'name' => $this->unit->name,
                ];
            }),
        ];
    }
}
