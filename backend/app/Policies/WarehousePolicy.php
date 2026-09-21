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

    public function create(User $user): bool
    {
        return $this->permissions->can('warehouses.manage');
    }
}
