<?php

namespace App\Actions\Platform;

use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Platform\FeatureCatalogue;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use Illuminate\Validation\ValidationException;

class UpsertTenantFeatureOverrideAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    public function execute(Tenant $tenant, string $featureKey, bool $enabled, string $reason): TenantFeatureOverride
    {
        if (! FeatureCatalogue::isValid($featureKey)) {
            throw ValidationException::withMessages([
                'feature_key' => 'Unknown feature key.',
            ]);
        }

        $override = TenantFeatureOverride::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'feature_key' => $featureKey],
            [
                'enabled' => $enabled,
                'reason' => $reason,
                'actor_platform_user_id' => $this->context->hasUser() ? $this->context->user()->id : null,
            ],
        );

        $this->audit->record('TENANT_FEATURE_OVERRIDE_CHANGED', [
            'resource_type' => 'tenant',
            'resource_ulid' => $tenant->ulid,
            'feature_key' => $featureKey,
            'enabled' => $enabled,
            'reason' => $reason,
        ], null, $this->context->hasUser() ? $this->context->user() : null);

        return $override;
    }

    public function clear(Tenant $tenant, string $featureKey, string $reason): void
    {
        TenantFeatureOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('feature_key', $featureKey)
            ->delete();

        $this->audit->record('TENANT_FEATURE_OVERRIDE_CHANGED', [
            'resource_type' => 'tenant',
            'resource_ulid' => $tenant->ulid,
            'feature_key' => $featureKey,
            'enabled' => null,
            'reason' => $reason,
        ], null, $this->context->hasUser() ? $this->context->user() : null);
    }
}
