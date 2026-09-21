<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformDevice
 */
class PlatformDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'status' => $this->status->value,
            'trusted' => $this->isTrusted(),
            'trusted_until' => $this->trusted_until?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'registered_at' => $this->registered_at?->toISOString(),
        ];
    }
}
