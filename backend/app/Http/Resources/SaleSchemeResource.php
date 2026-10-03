<?php

namespace App\Http\Resources;

use App\Models\SaleScheme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleScheme
 */
class SaleSchemeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'scheme_type' => $this->scheme_type->value,
            'apply_mode' => $this->apply_mode->value,
            'min_sale_amount' => $this->min_sale_amount,
            'max_reward_qty' => $this->max_reward_qty,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_stackable' => $this->is_stackable,
            'is_active' => $this->is_active,
            'reward_product' => $this->when(
                $this->relationLoaded('rewardProduct') && $this->rewardProduct,
                fn () => [
                    'ulid' => $this->rewardProduct->ulid,
                    'name' => $this->rewardProduct->name,
                    'product_number' => $this->rewardProduct->product_number,
                    'is_active' => $this->rewardProduct->is_active,
                ],
            ),
        ];
    }
}
