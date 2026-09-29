<?php

namespace App\Http\Resources;

use App\Models\AccountType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountType
 */
class AccountTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'is_cash' => (bool) $this->is_cash,
            'is_bank' => (bool) $this->is_bank,
            'is_receivable' => (bool) $this->is_receivable,
            'is_payable' => (bool) $this->is_payable,
            'pnl_grouping_label' => $this->pnl_grouping_label,
            'hint' => $this->hint,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'sub_head' => $this->whenLoaded('subHead', fn () => [
                'ulid' => $this->subHead->ulid,
                'name' => $this->subHead->name,
                'main_head_ulid' => $this->subHead->relationLoaded('mainHead')
                    ? $this->subHead->mainHead?->ulid
                    : null,
            ]),
            'sub_head_ulid' => $this->whenLoaded('subHead', fn () => $this->subHead->ulid),
        ];
    }
}
