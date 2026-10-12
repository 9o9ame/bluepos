<?php

namespace App\Http\Controllers\Api;

use App\Actions\Warehouses\CreateWarehouseAction;
use App\Actions\Warehouses\UpdateWarehouseAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Warehouse::class);

        return WarehouseResource::collection(
            Warehouse::query()
                ->forTenant($tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request, CreateWarehouseAction $create): JsonResponse
    {
        $this->authorize('create', Warehouse::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        return (new WarehouseResource($create->execute($data)->load('branch')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        Request $request,
        string $warehouseUlid,
        TenantContext $tenantContext,
        UpdateWarehouseAction $update,
    ): WarehouseResource {
        $warehouse = Warehouse::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('ulid', $warehouseUlid)
            ->firstOrFail();

        $this->authorize('update', $warehouse);

        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:32'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'status' => ['sometimes', 'required', Rule::enum(\App\Enums\WarehouseStatus::class)],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        return new WarehouseResource($update->execute($warehouse, $data));
    }
}
