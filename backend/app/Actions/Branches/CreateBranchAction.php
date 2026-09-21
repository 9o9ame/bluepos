<?php

namespace App\Actions\Branches;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBranchAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    /**
     * @param  array{code: string, name: string}  $data
     */
    public function execute(array $data): Branch
    {
        $tenant = $this->tenantContext->tenant();
        $code = strtoupper(trim($data['code']));

        return DB::transaction(function () use ($tenant, $data, $code): Branch {
            $this->entitlements->assertCanCreateBranch($tenant);

            if (Branch::query()->forTenant($tenant->id)->where('code', $code)->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'This branch code is already in use.',
                ]);
            }

            return Branch::query()->create([
                'tenant_id' => $tenant->id,
                'code' => $code,
                'name' => $data['name'],
                'status' => BranchStatus::Active,
                'is_default' => false,
            ]);
        });
    }
}
