<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\User;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;

class WarehousePolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can('inventory.view')
            || $this->permissions->can('branches.view')
            || $this->permissions->can('warehouses.manage')
            || $this->permissions->can('products.view');
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('warehouses.manage');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->permissions->can('warehouses.manage')
            && $this->tenantContext->hasTenant()
            && (int) $warehouse->tenant_id === $this->tenantContext->tenantId()
            && (int) $warehouse->branch_id === $this->tenantContext->branchId();
    }
}
