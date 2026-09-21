<?php

namespace App\Actions\Branches;

use App\Actions\Auth\EstablishAuthSessionAction;
use App\Authz\MembershipAccess;
use App\Enums\BranchStatus;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class SwitchActiveBranchAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MembershipAccess $membershipAccess,
    ) {}

    public function execute(Request $request, string $branchUlid): Branch
    {
        $branch = Branch::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $branchUlid)
            ->where('status', BranchStatus::Active)
            ->first();

        if (! $branch || ! $this->membershipAccess->canAccessBranch($this->tenantContext->membership(), (int) $branch->id)) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ($this->tenantContext->hasDevice() && $this->tenantContext->device()->branch_id
            && (int) $this->tenantContext->device()->branch_id !== (int) $branch->id) {
            throw new ApiException('BRANCH_ACCESS_DENIED', 'This device is not assigned to that branch.', 403);
        }

        $warehouse = Warehouse::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('branch_id', $branch->id)
            ->where('status', WarehouseStatus::Active)
            ->orderByDesc('is_default')
            ->first();

        if (! $warehouse) {
            throw new ApiException('NO_WAREHOUSE', 'No warehouse is available for this branch.', 403);
        }

        $request->session()->put([
            EstablishAuthSessionAction::BRANCH_ID => $branch->id,
            EstablishAuthSessionAction::WAREHOUSE_ID => $warehouse->id,
        ]);

        $this->tenantContext->hydrate(
            $this->tenantContext->user(),
            $this->tenantContext->tenant(),
            $this->tenantContext->membership(),
            $branch,
            $warehouse,
            $this->tenantContext->hasDevice() ? $this->tenantContext->device() : null,
        );

        return $branch->load('warehouses');
    }
}
