<?php

namespace App\Actions\Platform;

use App\Enums\TenantStatus;
use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;

class ActivateTenantAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    public function execute(Tenant $tenant): Tenant
    {
        if ($tenant->status === TenantStatus::Cancelled) {
            throw new ApiException('TENANT_DISABLED', 'Cancelled tenants cannot be reactivated this way.', 403);
        }

        $tenant->status = TenantStatus::Active;
        $tenant->save();

        $this->audit->record('TENANT_ACTIVATED', [
            'resource_type' => 'tenant',
            'resource_ulid' => $tenant->ulid,
        ], null, $this->context->hasUser() ? $this->context->user() : null);

        return $tenant->fresh() ?? $tenant;
    }
}
