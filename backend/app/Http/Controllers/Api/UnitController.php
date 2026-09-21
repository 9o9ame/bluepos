<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitRequest;
use App\Http\Requests\Catalog\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class UnitController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Unit::class);

        return UnitResource::collection(
            Unit::query()->forTenant($tenantContext->tenantId())->orderBy('code')->get()
        );
    }

    public function store(StoreUnitRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Unit::class);

        $unit = Unit::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new UnitResource($unit))->response()->setStatusCode(201);
    }

    public function show(string $unitUlid): UnitResource
    {
        $unit = $this->catalog->unit($unitUlid);
        $this->authorize('view', $unit);

        return new UnitResource($unit);
    }

    public function update(UpdateUnitRequest $request, string $unitUlid): UnitResource
    {
        $unit = $this->catalog->unit($unitUlid);
        $this->authorize('update', $unit);
        $unit->fill($request->validated());
        $unit->save();

        return new UnitResource($unit);
    }

    public function destroy(string $unitUlid, TenantContext $tenantContext): JsonResponse
    {
        $unit = $this->catalog->unit($unitUlid);
        $this->authorize('delete', $unit);

        $inUse = Product::query()->forTenant($tenantContext->tenantId())
            ->where(function ($query) use ($unit): void {
                $query->where('base_unit_id', $unit->id)->orWhere('secondary_unit_id', $unit->id);
            })
            ->exists()
            || ProductBarcode::query()->forTenant($tenantContext->tenantId())->where('unit_id', $unit->id)->exists();

        if ($inUse) {
            $unit->is_active = false;
            $unit->save();

            return response()->json(['ok' => true, 'archived' => true]);
        }

        $unit->delete();

        return response()->json(['ok' => true]);
    }
}
