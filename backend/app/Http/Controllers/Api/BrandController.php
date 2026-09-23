<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

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

        $brand = DB::transaction(function () use ($request, $tenantContext): Brand {
            $brand = Brand::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                ...$request->validated(),
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->audit->record('BRAND_CREATED', [
                'resource_type' => 'brand',
                'resource_ulid' => $brand->ulid,
            ]);

            return $brand;
        });

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
        DB::transaction(function () use ($brand, $request): void {
            $brand->fill($request->validated());
            $brand->save();
            $this->audit->record('BRAND_UPDATED', [
                'resource_type' => 'brand',
                'resource_ulid' => $brand->ulid,
            ]);
        });

        return new BrandResource($brand->refresh());
    }

    public function destroy(string $brandUlid): JsonResponse
    {
        $brand = $this->catalog->brand($brandUlid);
        $this->authorize('delete', $brand);

        DB::transaction(function () use ($brand): void {
            $brand->is_active = false;
            $brand->save();
            $this->audit->record('BRAND_DEACTIVATED', [
                'resource_type' => 'brand',
                'resource_ulid' => $brand->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
