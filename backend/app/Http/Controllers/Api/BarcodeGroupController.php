<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBarcodeGroupRequest;
use App\Http\Requests\Catalog\UpdateBarcodeGroupRequest;
use App\Http\Resources\BarcodeGroupResource;
use App\Models\BarcodeGroup;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BarcodeGroupController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', BarcodeGroup::class);

        return BarcodeGroupResource::collection(
            BarcodeGroup::query()
                ->forTenant($tenantContext->tenantId())
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreBarcodeGroupRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', BarcodeGroup::class);

        $barcodeGroup = DB::transaction(function () use ($request, $tenantContext): BarcodeGroup {
            $barcodeGroup = BarcodeGroup::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                ...$request->validated(),
                'is_active' => $request->boolean('is_active', true),
                'sort_order' => $request->integer('sort_order'),
            ]);

            $this->audit->record('BARCODE_GROUP_CREATED', [
                'resource_type' => 'barcode_group',
                'resource_ulid' => $barcodeGroup->ulid,
            ]);

            return $barcodeGroup;
        });

        return (new BarcodeGroupResource($barcodeGroup))->response()->setStatusCode(201);
    }

    public function show(string $barcodeGroupUlid): BarcodeGroupResource
    {
        $barcodeGroup = $this->catalog->barcodeGroup($barcodeGroupUlid);
        $this->authorize('view', $barcodeGroup);

        return new BarcodeGroupResource($barcodeGroup);
    }

    public function update(
        UpdateBarcodeGroupRequest $request,
        string $barcodeGroupUlid,
    ): BarcodeGroupResource {
        $barcodeGroup = $this->catalog->barcodeGroup($barcodeGroupUlid);
        $this->authorize('update', $barcodeGroup);

        DB::transaction(function () use ($barcodeGroup, $request): void {
            $barcodeGroup->fill($request->validated());
            $barcodeGroup->save();
            $this->audit->record('BARCODE_GROUP_UPDATED', [
                'resource_type' => 'barcode_group',
                'resource_ulid' => $barcodeGroup->ulid,
            ]);
        });

        return new BarcodeGroupResource($barcodeGroup->refresh());
    }

    public function destroy(string $barcodeGroupUlid): JsonResponse
    {
        $barcodeGroup = $this->catalog->barcodeGroup($barcodeGroupUlid);
        $this->authorize('delete', $barcodeGroup);

        DB::transaction(function () use ($barcodeGroup): void {
            $barcodeGroup->is_active = false;
            $barcodeGroup->save();
            $this->audit->record('BARCODE_GROUP_DEACTIVATED', [
                'resource_type' => 'barcode_group',
                'resource_ulid' => $barcodeGroup->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
