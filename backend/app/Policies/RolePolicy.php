<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;

class RolePolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->permissions->can('roles.view')
            && $this->tenantContext->hasTenant()
            && $role->tenant_id === $this->tenantContext->tenantId();
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->permissions->can('roles.edit')
            && $this->tenantContext->hasTenant()
            && $role->tenant_id === $this->tenantContext->tenantId();
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->permissions->can('roles.delete')
            && $this->tenantContext->hasTenant()
            && $role->tenant_id === $this->tenantContext->tenantId();
    }

    public function managePermissions(User $user, Role $role): bool
    {
        return $this->permissions->can('roles.manage_permissions')
            && $this->tenantContext->hasTenant()
            && $role->tenant_id === $this->tenantContext->tenantId();
    }
}
