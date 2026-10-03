<?php

namespace App\Http\Resources\Sales;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'line_kind' => $this->line_kind->value,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'notes' => $this->notes,
            'product' => $this->whenLoaded('product', fn () => [
                'ulid' => $this->product->ulid,
                'name' => $this->product->name,
                'product_number' => $this->product->product_number,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'ulid' => $this->unit->ulid,
                'code' => $this->unit->code,
            ]),
            'sale_scheme' => $this->whenLoaded('saleScheme', fn () => $this->saleScheme === null ? null : [
                'ulid' => $this->saleScheme->ulid,
                'name' => $this->saleScheme->name,
            ]),
        ];
    }
}
