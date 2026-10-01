<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchaseInvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseInvoiceLine
 */
class PurchaseInvoiceLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'quantity' => $this->quantity,
            'conversion_factor' => $this->conversion_factor,
            'base_quantity' => $this->base_quantity,
            'unit_cost' => $this->unit_cost,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'further_tax_amount' => $this->further_tax_amount,
            'line_total' => $this->line_total,
            'supplier_product_code' => $this->supplier_product_code,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'notes' => $this->notes,
            'brand_label' => $this->brand_label,
            'hs_code' => $this->hs_code,
            'pack_size' => $this->pack_size,
            'qty_ctn' => $this->qty_ctn,
            'free_pcs' => $this->free_pcs,
            'price_type' => $this->price_type,
            'mrp' => $this->mrp,
            'trade_disc_pct' => $this->trade_disc_pct,
            'regular_disc_pct' => $this->regular_disc_pct,
            'special_disc_pct' => $this->special_disc_pct,
            'tax_pct' => $this->tax_pct,
            'further_tax_pct' => $this->further_tax_pct,
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
