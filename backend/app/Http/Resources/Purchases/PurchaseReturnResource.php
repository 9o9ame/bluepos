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
        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'supplier_reference' => $this->supplier_reference,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'original_purchase' => $this->whenLoaded('purchaseInvoice', function () {
                return [
                    'ulid' => $this->purchaseInvoice->ulid,
                    'document_number' => $this->purchaseInvoice->document_number,
                    'supplier_invoice_number' => $this->purchaseInvoice->supplier_invoice_number,
                ];
            }),
            'supplier' => $this->whenLoaded('supplier', function () {
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
            'warehouse' => $this->whenLoaded('warehouse', function () {
                return [
                    'ulid' => $this->warehouse->ulid,
                    'code' => $this->warehouse->code,
                    'name' => $this->warehouse->name,
                ];
            }),
            'lines' => PurchaseReturnLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
