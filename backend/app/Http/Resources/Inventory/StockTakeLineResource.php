<?php

namespace App\Http\Resources\Inventory;

use App\Models\InventoryStockTakeLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryStockTakeLine
 */
class StockTakeLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'system_quantity' => $this->system_quantity,
            'counted_quantity' => $this->counted_quantity,
            'variance_quantity' => $this->variance_quantity,
            'notes' => $this->notes,
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
