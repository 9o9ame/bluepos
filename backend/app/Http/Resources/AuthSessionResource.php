<?php

namespace App\Http\Resources;

use App\Auth\AuthenticatedSession;
use App\Authz\PermissionService;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuthenticatedSession
 */
class AuthSessionResource extends JsonResource
{
    public static function fromContext(TenantContext $context): self
    {
        return new self(new AuthenticatedSession(
            user: $context->user(),
            tenant: $context->tenant(),
            membership: $context->membership(),
            branch: $context->branch(),
            warehouse: $context->warehouse(),
            device: $context->hasDevice() ? $context->device() : null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $permissions = app(PermissionService::class);
        $entitlements = app(TenantEntitlementService::class);
        $snapshot = $entitlements->snapshot($this->tenant);
        $subscription = $entitlements->subscription($this->tenant);

        return [
            'user' => new UserResource($this->user),
            'tenant' => new TenantResource($this->tenant),
            'membership' => new MembershipResource($this->membership),
            'branch' => new BranchResource($this->branch),
            'warehouse' => new WarehouseResource($this->warehouse),
            'roles' => RoleResource::collection(collect($permissions->roles())),
            'permissions' => $permissions->keys(),
            'device' => $this->device ? new DeviceResource($this->device) : null,
            'must_change_password' => (bool) $this->user->must_change_password,
            'branch_access' => $permissions->canAccessAllBranches() ? 'all_branches' : 'selected_branches',
            'entitlements' => [
                'plan' => $subscription?->plan ? [
                    'code' => $subscription->plan->code,
                    'name' => $subscription->plan->name,
                    'status' => $subscription->status->value,
                ] : null,
                'features' => $snapshot['features'],
                'limits' => $snapshot['limits'],
                'usage' => $snapshot['usage'],
            ],
        ];
    }
}
