<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'must_change_password' => (bool) $this->must_change_password,
            'status' => $this->status->value,
            'last_login_at' => $this->last_login_at?->toISOString(),
        ];
    }
}
