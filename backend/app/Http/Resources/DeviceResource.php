<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'device_type' => $this->device_type,
            'status' => $this->status->value,
            'registered_at' => $this->registered_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'ulid' => $this->approver->ulid,
                'name' => $this->approver->name,
            ] : null),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'last_sync_at' => $this->last_sync_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'trusted_until' => $this->trusted_until?->toISOString(),
            'app_version' => $this->app_version,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
        ];
    }
}
