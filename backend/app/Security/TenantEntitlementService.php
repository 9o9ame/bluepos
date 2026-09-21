<?php

namespace App\Security;

use App\Enums\DeviceStatus;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Models\TenantLimitOverride;
use App\Models\TenantSubscription;
use App\Models\Warehouse;
use App\Platform\FeatureCatalogue;
use App\Platform\LimitCatalogue;

class TenantEntitlementService
{
    public function tenantIsLicensed(Tenant $tenant): bool
    {
        return $this->isTenantOperational($tenant);
    }

    public function isTenantOperational(Tenant $tenant): bool
    {
        if (! in_array($tenant->status, [TenantStatus::Trial, TenantStatus::Active], true)) {
            return false;
        }

        $subscription = $this->subscription($tenant);
        if ($subscription === null) {
            return true;
        }

        return $subscription->isOperational();
    }

    public function assertOperational(Tenant $tenant): void
    {
        if (! $this->isTenantOperational($tenant)) {
            throw new ApiException('TENANT_DISABLED', 'This tenant is not active.', 403);
        }
    }

    public function hasFeature(Tenant $tenant, string $feature): bool
    {
        if (! FeatureCatalogue::isValid($feature) || ! $this->isTenantOperational($tenant)) {
            return false;
        }

        $override = TenantFeatureOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('feature_key', $feature)
            ->first();

        if ($override) {
            return (bool) $override->enabled;
        }

        $subscription = $this->subscription($tenant);
        if ($subscription === null) {
            return true;
        }

        $planFeature = $subscription->plan?->features?->firstWhere('feature_key', $feature);

        return (bool) ($planFeature?->enabled);
    }

    public function assertFeature(Tenant $tenant, string $feature): void
    {
        $this->assertOperational($tenant);
        if (! $this->hasFeature($tenant, $feature)) {
            throw new ApiException('FEATURE_DISABLED', 'This module is not enabled for the tenant.', 403);
        }
    }

    public function limit(Tenant $tenant, string $limitKey): ?int
    {
        if (! LimitCatalogue::isValid($limitKey)) {
            return null;
        }

        $override = TenantLimitOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('limit_key', $limitKey)
            ->first();

        if ($override) {
            return $override->value;
        }

        $subscription = $this->subscription($tenant);
        if ($subscription === null) {
            return null;
        }

        $planLimit = $subscription->plan?->limits?->firstWhere('limit_key', $limitKey);

        return $planLimit?->value;
    }

    public function usage(Tenant $tenant, string $limitKey): int
    {
        return match ($limitKey) {
            LimitCatalogue::MAX_USERS => Membership::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', MembershipStatus::Active)
                ->count(),
            LimitCatalogue::MAX_BRANCHES => $tenant->branches()->count(),
            LimitCatalogue::MAX_WAREHOUSES => Warehouse::query()->where('tenant_id', $tenant->id)->count(),
            LimitCatalogue::MAX_DEVICES => Device::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', DeviceStatus::Active)
                ->count(),
            default => 0,
        };
    }

    public function canCreateAdditionalUser(Tenant $tenant): bool
    {
        return $this->withinLimit($tenant, LimitCatalogue::MAX_USERS);
    }

    public function canCreateAdditionalBranch(Tenant $tenant): bool
    {
        return $this->withinLimit($tenant, LimitCatalogue::MAX_BRANCHES);
    }

    public function canCreateAdditionalWarehouse(Tenant $tenant): bool
    {
        return $this->withinLimit($tenant, LimitCatalogue::MAX_WAREHOUSES);
    }

    public function canRegisterAdditionalDevice(Tenant $tenant): bool
    {
        return $this->withinLimit($tenant, LimitCatalogue::MAX_DEVICES);
    }

    public function assertCanCreateUser(Tenant $tenant): void
    {
        if (! $this->canCreateAdditionalUser($tenant)) {
            throw new ApiException('PLAN_USER_LIMIT_REACHED', 'The user limit for this plan has been reached.', 403);
        }
    }

    public function assertCanCreateBranch(Tenant $tenant): void
    {
        if (! $this->canCreateAdditionalBranch($tenant)) {
            throw new ApiException('PLAN_BRANCH_LIMIT_REACHED', 'The branch limit for this plan has been reached.', 403);
        }
    }

    public function assertCanCreateWarehouse(Tenant $tenant): void
    {
        if (! $this->canCreateAdditionalWarehouse($tenant)) {
            throw new ApiException('PLAN_WAREHOUSE_LIMIT_REACHED', 'The warehouse limit for this plan has been reached.', 403);
        }
    }

    public function assertCanRegisterDevice(Tenant $tenant): void
    {
        if (! $this->canRegisterAdditionalDevice($tenant)) {
            throw new ApiException('PLAN_DEVICE_LIMIT_REACHED', 'The device limit for this plan has been reached.', 403);
        }
    }

    /**
     * @return array{features: list<string>, limits: array<string, int|null>, usage: array<string, int>}
     */
    public function snapshot(Tenant $tenant): array
    {
        $subscription = $this->subscription($tenant);
        if ($subscription?->plan) {
            $subscription->plan->loadMissing(['features', 'limits']);
        }

        $features = [];
        foreach (FeatureCatalogue::keys() as $key) {
            if ($this->hasFeature($tenant, $key)) {
                $features[] = $key;
            }
        }

        $limits = [];
        $usage = [];
        foreach (LimitCatalogue::keys() as $key) {
            $limits[$key] = $this->limit($tenant, $key);
            $usage[$key] = $this->usage($tenant, $key);
        }

        return [
            'features' => $features,
            'limits' => $limits,
            'usage' => $usage,
        ];
    }

    private function withinLimit(Tenant $tenant, string $limitKey): bool
    {
        $max = $this->limit($tenant, $limitKey);
        if ($max === null) {
            return true;
        }

        return $this->usage($tenant, $limitKey) < $max;
    }

    public function subscription(Tenant $tenant): ?TenantSubscription
    {
        return $tenant->relationLoaded('subscription')
            ? $tenant->getRelation('subscription')
            : TenantSubscription::query()->with('plan.features', 'plan.limits')->where('tenant_id', $tenant->id)->first();
    }
}
