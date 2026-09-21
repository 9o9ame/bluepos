<?php

namespace App\Authz;

use App\Enums\BranchAccess;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Membership;

class MembershipAccess
{
    public function canAccessAllBranches(Membership $membership): bool
    {
        return $membership->roles()
            ->where('is_active', true)
            ->where('roles.tenant_id', $membership->tenant_id)
            ->where('branch_access', BranchAccess::AllBranches)
            ->exists();
    }

    public function canAccessBranch(Membership $membership, int $branchId): bool
    {
        if ($this->canAccessAllBranches($membership)) {
            return Branch::query()
                ->forTenant((int) $membership->tenant_id)
                ->whereKey($branchId)
                ->exists();
        }

        return $membership->branches()
            ->where('branches.tenant_id', $membership->tenant_id)
            ->where('branches.id', $branchId)
            ->exists();
    }

    public function resolveBranch(Membership $membership, ?int $preferredBranchId): ?Branch
    {
        if ($preferredBranchId && $this->canAccessBranch($membership, $preferredBranchId)) {
            return Branch::query()
                ->forTenant((int) $membership->tenant_id)
                ->where('status', BranchStatus::Active)
                ->whereKey($preferredBranchId)
                ->first();
        }

        if ($this->canAccessAllBranches($membership)) {
            return Branch::query()
                ->forTenant((int) $membership->tenant_id)
                ->where('status', BranchStatus::Active)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->first();
        }

        return $membership->branches()
            ->where('branches.tenant_id', $membership->tenant_id)
            ->where('branches.status', BranchStatus::Active->value)
            ->orderByDesc('branches.is_default')
            ->orderBy('branches.name')
            ->first();
    }
}
