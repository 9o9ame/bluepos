<?php

namespace App\Http\Controllers\Api;

use App\Actions\Warehouses\CreateWarehouseAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'branch_ulid' => ['required', 'string', 'size:26'],
        ]);

        return (new WarehouseResource($create->execute($data)))
            ->response()
            ->setStatusCode(201);
    }
}
