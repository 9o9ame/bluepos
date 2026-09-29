<?php

namespace App\Http\Resources;

use App\Models\AccountSubHead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountSubHead
 */
class AccountSubHeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'main_head' => $this->whenLoaded('mainHead', fn () => [
                'ulid' => $this->mainHead->ulid,
                'name' => $this->mainHead->name,
            ]),
            'main_head_ulid' => $this->whenLoaded('mainHead', fn () => $this->mainHead->ulid),
        ];
    }
}
