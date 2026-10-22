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
        $revisionSnapshot = null;
        if ($this->relationLoaded('latestRevision') && $this->latestRevision) {
            $candidate = $this->latestRevision->after_snapshot;
            if (is_array($candidate)) {
                $revisionSnapshot = $candidate;
            }
        }

        $net = '0.0000';
        if ($revisionSnapshot !== null) {
            $net = (string) ($revisionSnapshot['net_amount'] ?? '0.0000');
        } elseif ($this->relationLoaded('lines')) {
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
            'adjustment_date' => $revisionSnapshot['adjustment_date'] ?? $this->adjustment_date?->toDateString(),
            'adjustment_type' => $this->adjustment_type,
            'status' => $this->status->value,
            'notes' => $revisionSnapshot['notes'] ?? $this->notes,
            'net_amount' => $net,
            'revision_number' => (int) ($revisionSnapshot['_meta']['revision_number'] ?? 0),
            'is_amended' => (int) ($revisionSnapshot['_meta']['revision_number'] ?? 0) > 0,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'warehouse' => $revisionSnapshot !== null
                ? ($revisionSnapshot['warehouse'] ?? null)
                : $this->whenLoaded('warehouse', fn () => [
                    'ulid' => $this->warehouse->ulid,
                    'code' => $this->warehouse->code,
                    'name' => $this->warehouse->name,
                ]),
            'account' => $revisionSnapshot !== null
                ? ($revisionSnapshot['account'] ?? null)
                : $this->whenLoaded('account', fn () => [
                    'ulid' => $this->account->ulid,
                    'code' => $this->account->code,
                    'name' => $this->account->name,
                ]),
            'lines' => $revisionSnapshot !== null
                ? ($revisionSnapshot['lines'] ?? [])
                : InventoryAdjustmentLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
