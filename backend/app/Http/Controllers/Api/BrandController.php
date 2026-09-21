<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Brand::class);

        return BrandResource::collection(
            Brand::query()->forTenant($tenantContext->tenantId())->orderBy('name')->get()
        );
    }

    public function store(StoreBrandRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $brand = Brand::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    public function show(string $brandUlid): BrandResource
    {
        $brand = $this->catalog->brand($brandUlid);
        $this->authorize('view', $brand);

        return new BrandResource($brand);
    }

    public function update(UpdateBrandRequest $request, string $brandUlid): BrandResource
    {
        $brand = $this->catalog->brand($brandUlid);
        $this->authorize('update', $brand);
        $brand->fill($request->validated());
        $brand->save();

        return new BrandResource($brand);
    }

    public function destroy(string $brandUlid): JsonResponse
    {
        $brand = $this->catalog->brand($brandUlid);
        $this->authorize('delete', $brand);

        if ($brand->products()->exists()) {
            $brand->is_active = false;
            $brand->save();

            return response()->json(['ok' => true, 'archived' => true]);
        }

        $brand->delete();

        return response()->json(['ok' => true]);
    }
}
