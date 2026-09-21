<?php

namespace App\Actions\Platform;

use App\Actions\Auth\ProvisionTenantAction;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePlatformTenantAction
{
    public function __construct(
        private readonly ProvisionTenantAction $provision,
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    /**
     * @param  array{
     *     tenant_name: string,
     *     tenant_code: string,
     *     legal_name?: ?string,
     *     recovery_email: string,
     *     admin_name: string,
     *     admin_username: string,
     *     timezone?: string,
     *     currency_code?: string,
     *     plan_ulid: string,
     *     status?: string,
     *     trial_starts_at?: ?string,
     *     trial_ends_at?: ?string,
     *     starts_at?: ?string,
     *     ends_at?: ?string,
     *     password?: ?string
     * }  $data
     * @return array{tenant: Tenant, temporary_password: ?string, username: string}
     */
    public function execute(array $data): array
    {
        $this->catalog->ensure();

        $plan = Plan::query()->where('ulid', $data['plan_ulid'])->first();
        if (! $plan) {
            throw ValidationException::withMessages([
                'plan_ulid' => 'The selected plan is invalid.',
            ]);
        }

        $generated = empty($data['password']);
        $password = $generated ? Str::password(16) : (string) $data['password'];
        $status = $data['status'] ?? TenantStatus::Active;
        if (! $status instanceof TenantStatus) {
            $status = TenantStatus::from((string) $status);
        }

        $session = DB::transaction(function () use ($data, $plan, $password, $status) {
            $session = $this->provision->execute([
                'name' => $data['admin_name'],
                'username' => $data['admin_username'],
                'recovery_email' => $data['recovery_email'],
                'password' => $password,
                'tenant_name' => $data['tenant_name'],
                'tenant_code' => $data['tenant_code'],
                'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                'currency_code' => $data['currency_code'] ?? 'PKR',
                'must_change_password' => true,
                'legal_name' => $data['legal_name'] ?? null,
                'status' => $status,
            ]);

            $subscriptionStatus = $status === TenantStatus::Trial
                ? SubscriptionStatus::Trial
                : SubscriptionStatus::Active;

            TenantSubscription::query()->create([
                'tenant_id' => $session->tenant->id,
                'plan_id' => $plan->id,
                'status' => $subscriptionStatus,
                'trial_starts_at' => $data['trial_starts_at'] ?? null,
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'starts_at' => $data['starts_at'] ?? now(),
                'ends_at' => $data['ends_at'] ?? null,
            ]);

            $this->audit->record('TENANT_CREATED', [
                'resource_type' => 'tenant',
                'resource_ulid' => $session->tenant->ulid,
                'plan_ulid' => $plan->ulid,
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            $this->audit->record('TENANT_ADMIN_CREATED', [
                'resource_type' => 'membership',
                'resource_ulid' => $session->membership->ulid,
                'tenant_ulid' => $session->tenant->ulid,
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            return $session;
        });

        return [
            'tenant' => $session->tenant->fresh(['subscription.plan']) ?? $session->tenant,
            'temporary_password' => $generated ? $password : null,
            'username' => $session->membership->username,
        ];
    }
}
