<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\User;
use App\Tenancy\TenantContext;

abstract class CatalogPolicy
{
    public function __construct(
        protected readonly TenantContext $tenantContext,
        protected readonly PermissionService $permissions,
    ) {}

    protected function allows(string $key, string $manageKey): bool
    {
        return $this->permissions->can($key) || $this->permissions->can($manageKey);
    }

    protected function sameTenant(int $tenantId): bool
    {
        return $this->tenantContext->hasTenant()
            && $tenantId === $this->tenantContext->tenantId();
    }
}
