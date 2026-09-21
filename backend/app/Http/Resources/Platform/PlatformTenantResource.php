<?php

namespace App\Http\Resources\Platform;

use App\Models\Tenant;
use App\Security\TenantEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class PlatformTenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;
        $plan = $subscription?->plan;

        $payload = [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
            'created_at' => $this->created_at?->toISOString(),
            'plan' => $plan ? [
                'ulid' => $plan->ulid,
                'code' => $plan->code,
                'name' => $plan->name,
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status->value,
                'trial_starts_at' => $subscription->trial_starts_at?->toISOString(),
                'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
                'starts_at' => $subscription->starts_at?->toISOString(),
                'ends_at' => $subscription->ends_at?->toISOString(),
                'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
            ] : null,
            'users_count' => $this->whenCounted('memberships'),
            'branches_count' => $this->whenCounted('branches'),
            'devices_count' => $this->when(isset($this->active_devices_count), $this->active_devices_count),
            'warehouses_count' => $this->whenCounted('warehouses'),
        ];

        if ($request->routeIs('platform.tenants.show')) {
            $entitlements = app(TenantEntitlementService::class);
            $payload['entitlements'] = $entitlements->snapshot($this->resource);
            $payload['feature_overrides'] = $this->whenLoaded('featureOverrides', fn () => $this->featureOverrides->map(fn ($row) => [
                'feature_key' => $row->feature_key,
                'enabled' => (bool) $row->enabled,
                'reason' => $row->reason,
                'updated_at' => $row->updated_at?->toISOString(),
            ])->values()->all());
            $payload['limit_overrides'] = $this->whenLoaded('limitOverrides', fn () => $this->limitOverrides->map(fn ($row) => [
                'limit_key' => $row->limit_key,
                'value' => $row->value,
                'reason' => $row->reason,
                'updated_at' => $row->updated_at?->toISOString(),
            ])->values()->all());
            $payload['admins'] = $this->whenLoaded('memberships', fn () => $this->memberships->map(fn ($membership) => [
                'ulid' => $membership->ulid,
                'username' => $membership->username,
                'status' => $membership->status->value,
                'is_owner' => (bool) $membership->is_owner,
                'user' => $membership->user ? [
                    'ulid' => $membership->user->ulid,
                    'name' => $membership->user->name,
                    'recovery_email' => $membership->user->recovery_email,
                    'must_change_password' => (bool) $membership->user->must_change_password,
                    'status' => $membership->user->status->value,
                ] : null,
                'roles' => $membership->relationLoaded('roles')
                    ? $membership->roles->map(fn ($role) => [
                        'ulid' => $role->ulid,
                        'code' => $role->code,
                        'name' => $role->name,
                    ])->values()->all()
                    : [],
            ])->values()->all());
        }

        return $payload;
    }
}
