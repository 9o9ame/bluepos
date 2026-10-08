<?php

namespace App\Http\Resources\Purchases;

use App\Models\PurchasePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchasePayment
 */
class PurchasePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'method' => $this->method->value,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'journal_entry_ulid' => $this->journal_entry_ulid,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
