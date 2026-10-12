<?php

namespace App\Http\Resources;

use App\Authz\PermissionCatalogue;
use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Membership
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'username' => $this->username,
            'status' => $this->status->value,
            'is_owner' => $this->hasRoleCode(PermissionCatalogue::OWNER),
            'user' => new UserResource($this->whenLoaded('user')),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'salesman' => $this->whenLoaded('salesmanProfile', fn (): ?array => $this->salesmanProfile ? [
                'ulid' => $this->salesmanProfile->ulid,
                'code' => $this->salesmanProfile->code,
                'name' => $this->salesmanProfile->name,
            ] : null),
            'salesman_start_time' => $this->salesman_start_time,
            'salesman_end_time' => $this->salesman_end_time,
            'fingerprint_ref' => $this->fingerprint_ref,
        ];
    }
}
