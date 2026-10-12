<?php

namespace App\Actions\Warehouses;

use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateWarehouseAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{
     *     code?: string,
     *     name?: string,
     *     status?: WarehouseStatus|string,
     *     is_default?: bool
     * }  $data
     */
    public function execute(Warehouse $warehouse, array $data): Warehouse
    {
        if ((int) $warehouse->tenant_id !== $this->tenantContext->tenantId()) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ((int) $warehouse->branch_id !== $this->tenantContext->branchId()) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            $branchWarehouses = Warehouse::query()
                ->forTenant($this->tenantContext->tenantId())
                ->where('branch_id', $this->tenantContext->branchId())
                ->lockForUpdate()
                ->get();

            $locked = $branchWarehouses->firstWhere('id', $warehouse->id);
            if (! $locked) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            $code = array_key_exists('code', $data)
                ? strtoupper(trim((string) $data['code']))
                : $locked->code;
            $name = array_key_exists('name', $data)
                ? trim((string) $data['name'])
                : $locked->name;
            $status = array_key_exists('status', $data)
                ? ($data['status'] instanceof WarehouseStatus
                    ? $data['status']
                    : WarehouseStatus::from((string) $data['status']))
                : $locked->status;
            $isDefault = array_key_exists('is_default', $data)
                ? (bool) $data['is_default']
                : (bool) $locked->is_default;

            if ($branchWarehouses
                ->where('id', '!=', $locked->id)
                ->contains(fn (Warehouse $candidate) => strtoupper($candidate->code) === $code)) {
                throw ValidationException::withMessages([
                    'code' => 'This warehouse code is already in use for this branch.',
                ]);
            }

            if ($status === WarehouseStatus::Inactive && (int) $locked->id === $this->tenantContext->warehouseId()) {
                throw ValidationException::withMessages([
                    'status' => 'The active session warehouse cannot be deactivated.',
                ]);
            }

            if ($status === WarehouseStatus::Inactive && $isDefault) {
                throw ValidationException::withMessages([
                    'status' => 'The default warehouse must remain active.',
                ]);
            }

            if ($locked->is_default && ! $isDefault) {
                throw ValidationException::withMessages([
                    'is_default' => 'Set another warehouse as default instead of clearing the current default.',
                ]);
            }

            if ($isDefault) {
                if ($status !== WarehouseStatus::Active) {
                    throw ValidationException::withMessages([
                        'is_default' => 'An inactive warehouse cannot be the default warehouse.',
                    ]);
                }

                Warehouse::query()
                    ->forTenant($this->tenantContext->tenantId())
                    ->where('branch_id', $this->tenantContext->branchId())
                    ->where('id', '!=', $locked->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $locked->forceFill([
                'code' => $code,
                'name' => $name,
                'status' => $status,
                'is_default' => $isDefault,
            ])->save();

            return $locked->refresh()->load('branch');
        });
    }
}
