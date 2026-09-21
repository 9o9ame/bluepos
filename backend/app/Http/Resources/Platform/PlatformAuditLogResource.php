<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformAuditLog
 */
class PlatformAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'event' => $this->event,
            'resource_type' => $this->resource_type,
            'resource_ulid' => $this->resource_ulid,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'ulid' => $this->actor->ulid,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
        ];
    }
}
