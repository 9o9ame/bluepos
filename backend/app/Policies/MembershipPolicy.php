<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;

class MembershipPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can('users.view');
    }

    public function view(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.view')
            && $this->sameTenant($membership);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('users.create');
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.edit')
            && $this->sameTenant($membership);
    }

    public function deactivate(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.deactivate')
            && $this->sameTenant($membership);
    }

    public function activate(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.activate')
            && $this->sameTenant($membership);
    }

    public function resetPassword(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.reset_password')
            && $this->sameTenant($membership);
    }

    public function forceLogout(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.force_logout')
            && $this->sameTenant($membership);
    }

    public function manageRoles(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.manage_roles')
            && $this->sameTenant($membership);
    }

    public function manageBranches(User $user, Membership $membership): bool
    {
        return $this->permissions->can('users.manage_branches')
            && $this->sameTenant($membership);
    }

    private function sameTenant(Membership $membership): bool
    {
        return $this->tenantContext->hasTenant()
            && $membership->tenant_id === $this->tenantContext->tenantId();
    }
}
