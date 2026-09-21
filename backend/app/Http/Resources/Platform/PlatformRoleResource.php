<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformRole
 */
class PlatformRoleResource extends JsonResource
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
            'is_system' => (bool) $this->is_system,
            'is_active' => (bool) $this->is_active,
            'type' => $this->is_system ? 'system' : 'custom',
            'users_assigned' => $this->whenCounted('users'),
            'updated_at' => $this->updated_at?->toISOString(),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn ($permission) => [
                'ulid' => $permission->ulid,
                'key' => $permission->key,
                'name' => $permission->name,
                'module' => $permission->module,
                'description' => $permission->description,
            ])->values()->all()),
        ];
    }
}
