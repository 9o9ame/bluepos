<?php

namespace App\Actions\Platform;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Platform\PlatformCatalogSync;
use Illuminate\Validation\ValidationException;

class AssignTenantPlanAction
{
    public function __construct(private readonly PlatformCatalogSync $catalog) {}

    public function execute(Tenant $tenant, string $planCode = 'ENTERPRISE'): TenantSubscription
    {
        $this->catalog->ensure();

        $plan = Plan::query()->where('code', strtoupper($planCode))->first();
        if (! $plan) {
            throw ValidationException::withMessages([
                'plan' => 'The selected plan is invalid.',
            ]);
        }

        $existing = TenantSubscription::query()->with('plan.features', 'plan.limits')->where('tenant_id', $tenant->id)->first();
        if ($existing) {
            return $existing;
        }

        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
        ]);

        $tenant->unsetRelation('subscription');

        return $subscription->fresh(['plan.features', 'plan.limits']) ?? $subscription;
    }
}
