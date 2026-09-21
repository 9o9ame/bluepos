<?php

namespace App\Authz;

use App\Enums\BranchAccess;
use App\Models\Branch;
use App\Models\Role;
use App\Tenancy\TenantContext;

class PermissionService
{
    /** @var list<string>|null */
    private ?array $keys = null;

    private ?bool $allBranches = null;

    /** @var list<int>|null */
    private ?array $branchIds = null;

    private ?int $hydratedMembershipId = null;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function can(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        if ($this->keys !== null && $this->hydratedMembershipId === $this->currentMembershipId()) {
            return $this->keys;
        }

        if (! $this->tenantContext->hasTenant()) {
            $this->keys = [];
            $this->hydratedMembershipId = null;

            return $this->keys;
        }

        $this->hydrate();

        return $this->keys ?? [];
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        if (! $this->tenantContext->hasTenant()) {
            return [];
        }

        return $this->tenantContext->membership()->roles()
            ->where('roles.tenant_id', $this->tenantContext->tenantId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function canAccessAllBranches(): bool
    {
        $this->keys();

        return $this->allBranches === true;
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->canAccessAllBranches()) {
            return Branch::query()
                ->forTenant($this->tenantContext->tenantId())
                ->whereKey($branchId)
                ->exists();
        }

        return in_array($branchId, $this->allowedBranchIds(), true);
    }

    /**
     * @return list<int>
     */
    public function allowedBranchIds(): array
    {
        $this->keys();

        if ($this->canAccessAllBranches()) {
            return Branch::query()
                ->forTenant($this->tenantContext->tenantId())
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $this->branchIds ?? [];
    }

    public function canAssignPrivilegedRole(Role $role): bool
    {
        if (! PermissionCatalogue::isPrivilegedSystemRole($role->code)) {
            return $this->can('users.manage_roles');
        }

        return $this->currentMembershipHasOwnerAuthority()
            && $this->can('users.manage_roles');
    }

    public function canGrantPermissionKey(string $key): bool
    {
        if (! PermissionCatalogue::isTenantKey($key)) {
            return false;
        }

        if (! $this->can('roles.manage_permissions')) {
            return false;
        }

        if ($this->currentMembershipHasOwnerAuthority()) {
            return true;
        }

        return $this->can($key);
    }

    private function hydrate(): void
    {
        $membership = $this->tenantContext->membership();

        $roles = $membership->roles()
            ->where('roles.tenant_id', $this->tenantContext->tenantId())
            ->where('is_active', true)
            ->with('permissions')
            ->get();

        $keys = [];
        $allBranches = false;

        foreach ($roles as $role) {
            if ($role->branch_access === BranchAccess::AllBranches) {
                $allBranches = true;
            }

            foreach ($role->permissions as $permission) {
                if (! $permission->is_platform) {
                    $keys[] = $permission->key;
                }
            }
        }

        $this->keys = array_values(array_unique($keys));
        $this->allBranches = $allBranches;
        $this->hydratedMembershipId = (int) $membership->id;

        if ($allBranches) {
            $this->branchIds = null;

            return;
        }

        $this->branchIds = $membership->branches()
            ->where('branches.tenant_id', $this->tenantContext->tenantId())
            ->pluck('branches.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function currentMembershipHasOwnerAuthority(): bool
    {
        if (! $this->tenantContext->hasTenant()) {
            return false;
        }

        return $this->tenantContext->membership()->hasOwnerAuthority($this->tenantContext->tenantId());
    }

    private function currentMembershipId(): ?int
    {
        if (! $this->tenantContext->hasTenant()) {
            return null;
        }

        return $this->tenantContext->membershipId();
    }
}
