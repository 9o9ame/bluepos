<?php

namespace App\Actions\Platform;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignTenantSubscriptionAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    /**
     * @param  array{
     *     plan_ulid: string,
     *     status: string,
     *     trial_starts_at?: ?string,
     *     trial_ends_at?: ?string,
     *     starts_at?: ?string,
     *     ends_at?: ?string,
     *     grace_ends_at?: ?string
     * }  $data
     */
    public function execute(Tenant $tenant, array $data): TenantSubscription
    {
        $plan = Plan::query()->where('ulid', $data['plan_ulid'])->first();
        if (! $plan) {
            throw ValidationException::withMessages([
                'plan_ulid' => 'The selected plan is invalid.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $plan, $data): TenantSubscription {
            $subscription = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            $previous = $subscription?->plan?->ulid;

            $status = $data['status'] instanceof SubscriptionStatus
                ? $data['status']
                : SubscriptionStatus::from($data['status']);

            if ($subscription) {
                $subscription->fill([
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'trial_starts_at' => $data['trial_starts_at'] ?? $subscription->trial_starts_at,
                    'trial_ends_at' => $data['trial_ends_at'] ?? $subscription->trial_ends_at,
                    'starts_at' => $data['starts_at'] ?? $subscription->starts_at,
                    'ends_at' => $data['ends_at'] ?? $subscription->ends_at,
                    'grace_ends_at' => $data['grace_ends_at'] ?? $subscription->grace_ends_at,
                ]);
                $subscription->save();
            } else {
                $subscription = TenantSubscription::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'trial_starts_at' => $data['trial_starts_at'] ?? null,
                    'trial_ends_at' => $data['trial_ends_at'] ?? null,
                    'starts_at' => $data['starts_at'] ?? now(),
                    'ends_at' => $data['ends_at'] ?? null,
                    'grace_ends_at' => $data['grace_ends_at'] ?? null,
                ]);
            }

            $this->audit->record('TENANT_PLAN_CHANGED', [
                'resource_type' => 'tenant',
                'resource_ulid' => $tenant->ulid,
                'plan_ulid' => $plan->ulid,
                'previous_plan_ulid' => $previous,
                'subscription_status' => $subscription->status->value,
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            return $subscription->fresh(['plan']) ?? $subscription;
        });
    }
}
