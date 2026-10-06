<?php

namespace App\Http\Resources\Inventory;

use App\Models\InventoryStockTake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryStockTake
 */
class StockTakeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'count_date' => $this->count_date?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'warehouse' => $this->whenLoaded('warehouse', function () {
                return [
                    'ulid' => $this->warehouse->ulid,
                    'code' => $this->warehouse->code,
                    'name' => $this->warehouse->name,
                    'status' => $this->warehouse->status->value,
                ];
            }),
            'lines' => StockTakeLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
