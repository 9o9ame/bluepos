<?php

namespace App\Http\Resources\Inventory;

use App\Models\InventoryOpeningBalanceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryOpeningBalanceLine
 */
class OpeningBalanceLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'notes' => $this->notes,
            'product' => $this->whenLoaded('product', function () {
                return [
                    'ulid' => $this->product->ulid,
                    'product_number' => $this->product->product_number,
                    'sku' => $this->product->sku,
                    'name' => $this->product->name,
                    'is_active' => $this->product->is_active,
                ];
            }),
        ];
    }
}
