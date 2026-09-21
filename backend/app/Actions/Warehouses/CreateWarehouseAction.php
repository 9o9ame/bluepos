<?php

namespace App\Actions\Warehouses;

use App\Enums\WarehouseStatus;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateWarehouseAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    /**
     * @param  array{code: string, name: string, branch_ulid: string}  $data
     */
    public function execute(array $data): Warehouse
    {
        $tenant = $this->tenantContext->tenant();
        $code = strtoupper(trim($data['code']));

        return DB::transaction(function () use ($tenant, $data, $code): Warehouse {
            $this->entitlements->assertCanCreateWarehouse($tenant);

            $branch = Branch::query()
                ->forTenant($tenant->id)
                ->where('ulid', $data['branch_ulid'])
                ->first();

            if (! $branch) {
                throw ValidationException::withMessages([
                    'branch_ulid' => 'The selected branch is invalid.',
                ]);
            }

            if (Warehouse::query()->forTenant($tenant->id)->where('code', $code)->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'This warehouse code is already in use.',
                ]);
            }

            return Warehouse::query()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'code' => $code,
                'name' => $data['name'],
                'status' => WarehouseStatus::Active,
                'is_default' => false,
            ]);
        });
    }
}
