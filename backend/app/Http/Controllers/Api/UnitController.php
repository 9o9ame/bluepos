<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitRequest;
use App\Http\Requests\Catalog\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

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

        $unit = DB::transaction(function () use ($request, $tenantContext): Unit {
            $unit = Unit::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                ...$request->validated(),
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->audit->record('UNIT_CREATED', [
                'resource_type' => 'unit',
                'resource_ulid' => $unit->ulid,
            ]);

            return $unit;
        });

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
        DB::transaction(function () use ($unit, $request): void {
            $unit->fill($request->validated());
            $unit->save();
            $this->audit->record('UNIT_UPDATED', [
                'resource_type' => 'unit',
                'resource_ulid' => $unit->ulid,
            ]);
        });

        return new UnitResource($unit->refresh());
    }

    public function destroy(string $unitUlid): JsonResponse
    {
        $unit = $this->catalog->unit($unitUlid);
        $this->authorize('delete', $unit);

        DB::transaction(function () use ($unit): void {
            $unit->is_active = false;
            $unit->save();
            $this->audit->record('UNIT_DEACTIVATED', [
                'resource_type' => 'unit',
                'resource_ulid' => $unit->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
