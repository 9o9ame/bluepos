<?php

namespace App\Http\Resources;

use App\Models\AuthSessionRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuthSessionRecord
 */
class AuthSessionRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUlid = $request->session()->get(\App\Actions\Auth\EstablishAuthSessionAction::AUTH_SESSION_ULID);

        return [
            'ulid' => $this->ulid,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'is_current' => $this->ulid === $currentUlid,
            'membership' => $this->whenLoaded('membership', fn () => [
                'ulid' => $this->membership?->ulid,
                'username' => $this->membership?->username,
                'user' => $this->membership?->user ? [
                    'ulid' => $this->membership->user->ulid,
                    'name' => $this->membership->user->name,
                ] : null,
            ]),
            'device' => new DeviceResource($this->whenLoaded('device')),
        ];
    }
}
