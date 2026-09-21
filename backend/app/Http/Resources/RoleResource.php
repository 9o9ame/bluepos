<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'branch_access' => $this->branch_access->value,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
