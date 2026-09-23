<?php

namespace App\Http\Resources\Inventory;

use App\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockBalance
 */
class StockBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quantity' => $this->quantity,
            'average_cost' => $this->average_cost,
            'stock_value' => $this->stock_value,
            'product' => $this->whenLoaded('product', function () {
                return [
                    'ulid' => $this->product->ulid,
                    'product_number' => $this->product->product_number,
                    'sku' => $this->product->sku,
                    'name' => $this->product->name,
                ];
            }),
            'warehouse' => $this->whenLoaded('warehouse', function () {
                return [
                    'ulid' => $this->warehouse->ulid,
                    'code' => $this->warehouse->code,
                    'name' => $this->warehouse->name,
                ];
            }),
        ];
    }
}
