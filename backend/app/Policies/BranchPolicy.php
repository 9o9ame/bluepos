<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\Branch;
use App\Models\User;
use App\Tenancy\TenantContext;

class BranchPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can('branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->permissions->can('branches.view')
            && $this->tenantContext->hasTenant()
            && $branch->tenant_id === $this->tenantContext->tenantId()
            && $this->permissions->canAccessBranch((int) $branch->id);
    }

    public function switchBranch(User $user): bool
    {
        return $this->permissions->can('branches.switch');
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('branches.manage');
    }
}
