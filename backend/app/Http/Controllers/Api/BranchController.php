<?php

namespace App\Http\Controllers\Api;

use App\Actions\Branches\SwitchActiveBranchAction;
use App\Authz\PermissionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthSessionResource;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(TenantContext $tenantContext, PermissionService $permissions): mixed
    {
        $this->authorize('viewAny', Branch::class);

        $query = Branch::query()
            ->forTenant($tenantContext->tenantId())
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $permissions->can('users.manage_branches') && ! $permissions->canAccessAllBranches()) {
            $query->whereIn('id', $permissions->allowedBranchIds() ?: [0]);
        }

        return BranchResource::collection($query->get());
    }

    public function switch(
        Request $request,
        string $branchUlid,
        SwitchActiveBranchAction $switchActiveBranch,
        TenantContext $tenantContext,
    ): AuthSessionResource {
        $this->authorize('switchBranch', Branch::class);

        $switchActiveBranch->execute($request, $branchUlid);

        return AuthSessionResource::fromContext($tenantContext);
    }
}
