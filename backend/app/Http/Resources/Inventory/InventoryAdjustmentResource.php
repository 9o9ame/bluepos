<?php

namespace App\Http\Resources\Inventory;

use App\Models\InventoryAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryAdjustment */
class InventoryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $net = '0.0000';
        if ($this->relationLoaded('lines')) {
            foreach ($this->lines as $line) {
                $amount = bcadd((string) $line->total_amount, '0', 4);
                $delta = bcadd((string) $line->quantity_delta, '0', 6);
                $net = bccomp($delta, '0', 6) > 0
                    ? bcadd($net, $amount, 4)
                    : bcsub($net, $amount, 4);
            }
        }

        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'adjustment_type' => $this->adjustment_type,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'net_amount' => $net,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'account' => $this->whenLoaded('account', fn () => [
                'ulid' => $this->account->ulid,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ]),
            'lines' => InventoryAdjustmentLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
