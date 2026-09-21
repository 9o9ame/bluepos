<?php

namespace App\Http\Resources\Platform;

use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformSession
 */
class PlatformSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'current' => $this->ulid === $request->session()->get(EnsurePlatformContext::SESSION_ULID),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
