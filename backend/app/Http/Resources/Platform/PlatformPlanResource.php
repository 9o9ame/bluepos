<?php

namespace App\Http\Resources\Platform;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlatformPlanResource extends JsonResource
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
            'description' => $this->description,
            'status' => $this->status->value,
            'billing_interval' => $this->billing_interval,
            'features' => $this->whenLoaded('features', fn () => $this->features
                ->mapWithKeys(fn ($row) => [$row->feature_key => (bool) $row->enabled])
                ->all()),
            'limits' => $this->whenLoaded('limits', fn () => $this->limits
                ->mapWithKeys(fn ($row) => [$row->limit_key => $row->value])
                ->all()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
