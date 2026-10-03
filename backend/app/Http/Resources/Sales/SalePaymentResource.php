<?php

namespace App\Http\Resources\Sales;

use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalePayment
 */
class SalePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
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
