<?php

namespace App\Actions\Warehouses;

use App\Enums\WarehouseStatus;
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
     * @param  array{code: string, name: string}  $data
     */
    public function execute(array $data): Warehouse
    {
        $tenant = $this->tenantContext->tenant();
        $code = strtoupper(trim($data['code']));

        return DB::transaction(function () use ($tenant, $data, $code): Warehouse {
            $this->entitlements->assertCanCreateWarehouse($tenant);

            $branch = $this->tenantContext->branch();

            if (Warehouse::query()
                ->forTenant($tenant->id)
                ->where('branch_id', $branch->id)
                ->where('code', $code)
                ->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'This warehouse code is already in use for this branch.',
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
