<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'order_date' => $this->order_date?->toDateString(),
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'ulid' => $this->supplier->ulid,
                'code' => $this->supplier->code,
                'name' => $this->supplier->name,
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
            'items' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'ulid' => $line->ulid,
                'quantity' => $line->quantity,
                'conversion_factor' => $line->conversion_factor,
                'base_quantity' => $line->base_quantity,
                'unit_price' => $line->unit_price,
                'gross_amount' => $line->gross_amount,
                'discount_percent' => $line->discount_percent,
                'discount_amount' => $line->discount_amount,
                'line_total' => $line->line_total,
                'notes' => $line->notes,
                'product' => $line->product ? [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'name' => $line->product->name,
                ] : null,
                'unit' => $line->unit ? [
                    'ulid' => $line->unit->ulid,
                    'code' => $line->unit->code,
                    'name' => $line->unit->name,
                    'symbol' => $line->unit->symbol,
                ] : null,
            ])->values()),
        ];
    }
}
