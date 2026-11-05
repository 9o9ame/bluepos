<?php

namespace App\Http\Controllers\Platform;

use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\TenantImportRequest;
use App\Models\Tenant;
use App\Models\Platform\TenantImport;
use App\Models\Warehouse;
use App\Platform\Imports\TenantImportService;
use App\Platform\Imports\AsyncTenantImportService;
use App\Platform\PlatformContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TenantImportController extends Controller
{
    public function warehouses(string $tenantUlid, PlatformContext $context): JsonResponse
    {
        $tenant = Tenant::query()->where('ulid', $tenantUlid)->firstOrFail();
        Gate::forUser($context->user())->authorize('importData', $tenant);

        return response()->json(Warehouse::query()->forTenant($tenant->id)->where('status', WarehouseStatus::Active)
            ->whereHas('branch', fn ($q) => $q->forTenant($tenant->id)->where('status', 'active'))
            ->with('branch')->orderBy('name')->get()->map(fn ($w) => ['ulid' => $w->ulid,
                'name' => $w->name, 'branch_name' => $w->branch?->name]));
    }

    public function start(TenantImportRequest $request, string $tenantUlid,
        PlatformContext $context, AsyncTenantImportService $service): JsonResponse
    {
        $tenant = Tenant::query()->where('ulid', $tenantUlid)->firstOrFail();

        return response()->json(
            $service->start($tenant, $context->user(), $request->file('file'), $request->validated('warehouse_ulid')),
            202
        );
    }

    public function progress(string $tenantUlid, string $importUlid,
        PlatformContext $context, AsyncTenantImportService $service): JsonResponse
    {
        $tenant = Tenant::query()->where('ulid', $tenantUlid)->firstOrFail();
        Gate::forUser($context->user())->authorize('importData', $tenant);
        $batch = TenantImport::query()->where('tenant_id', $tenant->id)
            ->where('ulid', $importUlid)->firstOrFail();

        return response()->json($service->progress($tenant, $batch));
    }

    public function store(TenantImportRequest $request, string $tenantUlid, PlatformContext $context, TenantImportService $service): JsonResponse
    {
        $tenant = Tenant::query()->where('ulid', $tenantUlid)->firstOrFail();

        return response()->json($service->execute($tenant, $context->user(), $request->file('file'), $request->validated('warehouse_ulid')));
    }
}
