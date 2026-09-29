<?php

namespace App\Http\Resources;

use App\Models\PartyBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PartyBankAccount
 */
class PartyBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'bank_name' => $this->bank_name,
            'branch_name' => $this->branch_name,
            'branch_code' => $this->branch_code,
            'city' => $this->city,
            'account_number' => $this->account_number,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
