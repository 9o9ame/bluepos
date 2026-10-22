<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseReturn
 */
class PurchaseReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revisionSnapshot = null;
        if ($this->relationLoaded('latestRevision') && $this->latestRevision) {
            $candidate = $this->latestRevision->after_snapshot;
            if (is_array($candidate)) {
                $revisionSnapshot = $candidate;
            }
        }

        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'return_date' => $revisionSnapshot['return_date'] ?? $this->return_date?->toDateString(),
            'status' => $this->status->value,
            'return_mode' => $revisionSnapshot['return_mode']
                ?? ($this->purchase_invoice_id === null ? 'general' : 'invoice'),
            'subtotal' => $revisionSnapshot['subtotal'] ?? $this->subtotal,
            'discount_amount' => $revisionSnapshot['discount_amount'] ?? $this->discount_amount,
            'tax_amount' => $revisionSnapshot['tax_amount'] ?? $this->tax_amount,
            'grand_total' => $revisionSnapshot['grand_total'] ?? $this->grand_total,
            'supplier_reference' => $revisionSnapshot['supplier_reference'] ?? $this->supplier_reference,
            'reason' => $revisionSnapshot['reason'] ?? $this->reason,
            'notes' => $revisionSnapshot['notes'] ?? $this->notes,
            'revision_number' => (int) ($revisionSnapshot['_meta']['revision_number'] ?? 0),
            'is_amended' => (int) ($revisionSnapshot['_meta']['revision_number'] ?? 0) > 0,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'original_purchase' => $revisionSnapshot !== null
                ? ($revisionSnapshot['original_purchase'] ?? null)
                : $this->whenLoaded('purchaseInvoice', function () {
                    if (! $this->purchaseInvoice) {
                        return null;
                    }

                    return [
                        'ulid' => $this->purchaseInvoice->ulid,
                        'document_number' => $this->purchaseInvoice->document_number,
                        'supplier_invoice_number' => $this->purchaseInvoice->supplier_invoice_number,
                    ];
                }),
            'supplier' => $revisionSnapshot !== null
                ? ($revisionSnapshot['supplier'] ?? null)
                : $this->whenLoaded('supplier', function () {
                    return [
                        'ulid' => $this->supplier->ulid,
                        'code' => $this->supplier->code,
                        'name' => $this->supplier->name,
                    ];
                }),
            'branch' => $this->whenLoaded('branch', function () {
                return [
                    'ulid' => $this->branch->ulid,
                    'code' => $this->branch->code,
                    'name' => $this->branch->name,
                ];
            }),
            'warehouse' => $revisionSnapshot !== null
                ? ($revisionSnapshot['warehouse'] ?? null)
                : $this->whenLoaded('warehouse', function () {
                    return [
                        'ulid' => $this->warehouse->ulid,
                        'code' => $this->warehouse->code,
                        'name' => $this->warehouse->name,
                    ];
                }),
            'lines' => $revisionSnapshot !== null
                ? ($revisionSnapshot['lines'] ?? [])
                : PurchaseReturnLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
