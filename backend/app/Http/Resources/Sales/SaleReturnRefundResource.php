<?php

namespace App\Http\Resources\Sales;

use App\Models\SaleReturnRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturnRefund */
class SaleReturnRefundResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'method' => $this->method->value,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'journal_entry_ulid' => $this->journal_entry_ulid,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'ulid' => $this->account->ulid,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
