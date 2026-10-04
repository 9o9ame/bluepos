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
            'stock_quantity' => $this->stock_quantity,
            'barcode' => $this->barcode,
            'conversion_factor' => $this->conversion_factor,
            'price_type' => $this->price_type?->value,
            'unit_price' => $this->unit_price,
            'gross_amount' => $this->gross_amount,
            'discount_percent' => $this->discount_percent,
            'discount_amount' => $this->discount_amount,
            'tax_percent' => $this->tax_percent,
            'tax_amount' => $this->tax_amount,
            'net_amount' => $this->line_total,
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
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
                'allows_decimal' => $this->unit->allows_decimal,
            ]),
            'sale_scheme' => $this->whenLoaded('saleScheme', fn () => $this->saleScheme === null ? null : [
                'ulid' => $this->saleScheme->ulid,
                'name' => $this->saleScheme->name,
            ]),
        ];
    }
}
