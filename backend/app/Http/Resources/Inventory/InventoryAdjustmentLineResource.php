<?php

namespace App\Http\Resources\Inventory;

use App\Models\InventoryAdjustmentLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryAdjustmentLine */
class InventoryAdjustmentLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $delta = bcadd((string) $this->quantity_delta, '0', 6);

        return [
            'ulid' => $this->ulid,
            'in_stock' => $this->system_quantity,
            'qty_plus' => bccomp($delta, '0', 6) > 0 ? $delta : '0.000000',
            'qty_minus' => bccomp($delta, '0', 6) < 0 ? bcsub('0.000000', $delta, 6) : '0.000000',
            'after_stock' => $this->after_quantity,
            'price' => $this->unit_cost,
            'amount' => $this->total_amount,
            'remarks' => $this->notes,
            'product' => $this->whenLoaded('product', function () {
                return [
                    'ulid' => $this->product->ulid,
                    'product_number' => $this->product->product_number,
                    'sku' => $this->product->sku,
                    'name' => $this->product->name,
                ];
            }),
        ];
    }
}
