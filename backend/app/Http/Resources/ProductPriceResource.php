<?php

namespace App\Http\Resources;

use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductPrice
 */
class ProductPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'price_type' => $this->price_type->value,
            'amount' => $this->amount,
            'currency_code' => $this->currency_code,
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
