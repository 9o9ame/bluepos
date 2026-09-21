<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformUser
 */
class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'must_change_password' => (bool) $this->must_change_password,
            'password_changed_at' => $this->password_changed_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),
            'last_seen_at' => $this->lastSeenAt(),
            'last_mfa_verified_at' => $this->last_mfa_verified_at?->toISOString(),
            'mfa' => [
                'method' => 'email_otp',
                'enabled' => true,
            ],
            'permissions' => $this->permissionKeys(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'ulid' => $role->ulid,
                'code' => $role->code,
                'name' => $role->name,
            ])->values()->all()),
        ];
    }

    private function lastSeenAt(): ?string
    {
        $max = $this->sessions_max_last_seen_at ?? null;
        if (is_string($max) && $max !== '') {
            return $max;
        }

        return $this->last_login_at?->toISOString();
    }
}
