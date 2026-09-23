<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSupplierRequest;
use App\Http\Requests\Catalog\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Supplier::class);

        return SupplierResource::collection(
            Supplier::query()->forTenant($tenantContext->tenantId())->orderBy('name')->get()
        );
    }

    public function store(StoreSupplierRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = DB::transaction(function () use ($request, $tenantContext): Supplier {
            $supplier = Supplier::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                ...$request->validated(),
                'is_active' => $request->boolean('is_active', true),
                'created_by' => $tenantContext->userId(),
            ]);
            $this->audit->record('SUPPLIER_CREATED', [
                'resource_type' => 'supplier',
                'resource_ulid' => $supplier->ulid,
            ]);

            return $supplier;
        });

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function show(string $supplierUlid): SupplierResource
    {
        $supplier = $this->catalog->supplier($supplierUlid);
        $this->authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, string $supplierUlid): SupplierResource
    {
        $supplier = $this->catalog->supplier($supplierUlid);
        $this->authorize('update', $supplier);

        DB::transaction(function () use ($supplier, $request): void {
            $wasActive = (bool) $supplier->is_active;
            $supplier->fill($request->validated());
            $supplier->updated_by = app(TenantContext::class)->userId();
            $supplier->save();

            $becameActive = ! $wasActive && (bool) $supplier->is_active;
            $this->audit->record($becameActive ? 'SUPPLIER_ACTIVATED' : 'SUPPLIER_UPDATED', [
                'resource_type' => 'supplier',
                'resource_ulid' => $supplier->ulid,
            ]);
        });

        return new SupplierResource($supplier->refresh());
    }

    public function destroy(string $supplierUlid): JsonResponse
    {
        $supplier = $this->catalog->supplier($supplierUlid);
        $this->authorize('delete', $supplier);

        DB::transaction(function () use ($supplier): void {
            $supplier->is_active = false;
            $supplier->updated_by = app(TenantContext::class)->userId();
            $supplier->save();
            $this->audit->record('SUPPLIER_DEACTIVATED', [
                'resource_type' => 'supplier',
                'resource_ulid' => $supplier->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
