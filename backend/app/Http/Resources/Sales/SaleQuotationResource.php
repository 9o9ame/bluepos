<?php

namespace App\Http\Resources\Sales;

use App\Models\SaleQuotation;
use App\Models\SaleQuotationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleQuotation */
class SaleQuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'quotation_date' => $this->quotation_date?->toDateString(),
            'price_type' => $this->price_type?->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
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
            'items' => $this->whenLoaded('items', fn () =>
                $this->items->map(fn (SaleQuotationItem $item): array => [
                    'ulid' => $item->ulid,
                    'line_kind' => $item->line_kind->value,
                    'quantity' => $item->quantity,
                    'stock_quantity' => $item->stock_quantity,
                    'barcode' => $item->barcode,
                    'conversion_factor' => $item->conversion_factor,
                    'price_type' => $item->price_type?->value,
                    'unit_price' => $item->unit_price,
                    'gross_amount' => $item->gross_amount,
                    'discount_percent' => $item->discount_percent,
                    'discount_amount' => $item->discount_amount,
                    'tax_percent' => $item->tax_percent,
                    'tax_amount' => $item->tax_amount,
                    'net_amount' => $item->line_total,
                    'line_total' => $item->line_total,
                    'notes' => $item->notes,
                    'product' => [
                        'ulid' => $item->product->ulid,
                        'product_number' => $item->product->product_number,
                        'name' => $item->product->name,
                    ],
                    'unit' => [
                        'ulid' => $item->unit->ulid,
                        'code' => $item->unit->code,
                        'name' => $item->unit->name,
                        'symbol' => $item->unit->symbol,
                        'allows_decimal' => $item->unit->allows_decimal,
                    ],
                    'sale_scheme' => $item->saleScheme ? [
                        'ulid' => $item->saleScheme->ulid,
                        'name' => $item->saleScheme->name,
                    ] : null,
                ])->values()->all()
            ),
        ];
    }
}
