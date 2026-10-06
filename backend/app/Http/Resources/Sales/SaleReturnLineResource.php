<?php

namespace App\Http\Resources\Sales;

use App\Models\SaleReturnLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturnLine */
class SaleReturnLineResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'original_sale_item_ulid' => $this->whenLoaded('saleItem', fn () => $this->saleItem?->ulid),
            'line_kind' => $this->line_kind->value,
            'barcode' => $this->barcode,
            'quantity' => $this->quantity,
            'conversion_factor' => $this->conversion_factor,
            'stock_quantity' => $this->stock_quantity,
            'unit_price' => $this->unit_price,
            'gross_amount' => $this->gross_amount,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'product' => $this->whenLoaded('product', fn () => [
                'ulid' => $this->product->ulid,
                'product_number' => $this->product->product_number,
                'name' => $this->product->name,
                'category' => $this->product->relationLoaded('category') && $this->product->category ? [
                    'ulid' => $this->product->category->ulid,
                    'name' => $this->product->category->name,
                ] : null,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'ulid' => $this->unit->ulid,
                'code' => $this->unit->code,
                'name' => $this->unit->name,
            ] : null),
        ];
    }
}
