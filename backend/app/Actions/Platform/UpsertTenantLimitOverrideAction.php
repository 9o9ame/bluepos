<?php

namespace App\Actions\Platform;

use App\Models\Tenant;
use App\Models\TenantLimitOverride;
use App\Platform\LimitCatalogue;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use Illuminate\Validation\ValidationException;

class UpsertTenantLimitOverrideAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    public function execute(Tenant $tenant, string $limitKey, ?int $value, string $reason): TenantLimitOverride
    {
        if (! LimitCatalogue::isValid($limitKey)) {
            throw ValidationException::withMessages([
                'limit_key' => 'Unknown limit key.',
            ]);
        }

        $override = TenantLimitOverride::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'limit_key' => $limitKey],
            [
                'value' => $value,
                'reason' => $reason,
                'actor_platform_user_id' => $this->context->hasUser() ? $this->context->user()->id : null,
            ],
        );

        $this->audit->record('TENANT_LIMIT_OVERRIDE_CHANGED', [
            'resource_type' => 'tenant',
            'resource_ulid' => $tenant->ulid,
            'limit_key' => $limitKey,
            'value' => $value,
            'reason' => $reason,
        ], null, $this->context->hasUser() ? $this->context->user() : null);

        return $override;
    }

    public function clear(Tenant $tenant, string $limitKey, string $reason): void
    {
        TenantLimitOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('limit_key', $limitKey)
            ->delete();

        $this->audit->record('TENANT_LIMIT_OVERRIDE_CHANGED', [
            'resource_type' => 'tenant',
            'resource_ulid' => $tenant->ulid,
            'limit_key' => $limitKey,
            'value' => null,
            'reason' => $reason,
        ], null, $this->context->hasUser() ? $this->context->user() : null);
    }
}
