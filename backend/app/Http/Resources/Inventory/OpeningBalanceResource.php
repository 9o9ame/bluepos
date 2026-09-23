<?php

namespace App\Http\Resources\Inventory;

use App\Models\InventoryOpeningBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryOpeningBalance
 */
class OpeningBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->toDateString(),
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
            'lines' => OpeningBalanceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
