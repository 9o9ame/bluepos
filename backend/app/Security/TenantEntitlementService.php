<?php

namespace App\Security;

final class TenantEntitlementService
{
    public function tenantIsLicensed(\App\Models\Tenant $tenant): bool
    {
        return in_array($tenant->status->value, ['trial', 'active'], true);
    }

    public function featureEnabled(\App\Models\Tenant $tenant, string $feature): bool
    {
        unset($feature);

        return $this->tenantIsLicensed($tenant);
    }
}
