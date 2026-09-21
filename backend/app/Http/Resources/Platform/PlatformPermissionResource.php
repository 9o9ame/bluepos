<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\PlatformPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformPermission
 */
class PlatformPermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'key' => $this->key,
            'name' => $this->name,
            'module' => $this->module,
            'description' => $this->description,
        ];
    }
}
