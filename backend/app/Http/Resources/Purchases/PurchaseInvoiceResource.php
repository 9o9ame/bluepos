<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchaseInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseInvoice
 */
class PurchaseInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'document_number' => $this->document_number,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'freight_amount' => $this->freight_amount,
            'other_charges' => $this->other_charges,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at?->toIso8601String(),
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
            'lines' => PurchaseInvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
