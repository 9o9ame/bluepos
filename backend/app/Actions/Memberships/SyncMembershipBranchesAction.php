<?php

namespace App\Actions\Memberships;

use App\Enums\BranchAccess;
use App\Models\Branch;
use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncMembershipBranchesAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  list<string>  $branchUlids
     */
    public function execute(Membership $membership, array $branchUlids): Membership
    {
        $tenantId = $this->tenantContext->tenantId();

        $hasAllBranchRole = $membership->roles()
            ->where('roles.tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('branch_access', BranchAccess::AllBranches)
            ->exists();

        $requiresSelection = ! $hasAllBranchRole;

        $branches = Branch::query()
            ->forTenant($tenantId)
            ->whereIn('ulid', $branchUlids)
            ->get();

        if ($branches->count() !== count(array_unique($branchUlids))) {
            throw ValidationException::withMessages([
                'branches' => 'One or more branches were not found in this tenant.',
            ]);
        }

        if ($requiresSelection && $branches->isEmpty()) {
            throw ValidationException::withMessages([
                'branches' => 'At least one branch is required for this membership.',
            ]);
        }

        $current = $membership->branches()->pluck('branches.id')->all();
        $desired = $branches->pluck('id')->all();

        $detach = array_diff($current, $desired);
        if ($detach !== []) {
            $membership->branches()->detach($detach);
        }

        foreach (array_diff($desired, $current) as $branchId) {
            $membership->branches()->attach($branchId, [
                'ulid' => (string) Str::ulid(),
            ]);
        }

        return $membership->fresh(['roles', 'user', 'branches']);
    }
}
