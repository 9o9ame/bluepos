<?php

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\StoreSaleHoldAction;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleHoldRequest;
use App\Http\Resources\Sales\SaleHoldResource;
use App\Models\SaleHold;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class SaleHoldController extends Controller
{
    public function index(
        TenantContext $tenantContext,
        PermissionService $permissions,
    ): array {
        abort_unless($permissions->can('sales.recall'), 403);

        $holds = SaleHold::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->with(['customer', 'salesmanParty', 'branch', 'warehouse'])
            ->withCount([
                'items as sale_line_count' => fn ($query) => $query->where('line_kind', 'sale'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return [
            'data' => SaleHoldResource::collection($holds),
            'count' => $holds->count(),
        ];
    }

    public function store(
        StoreSaleHoldRequest $request,
        StoreSaleHoldAction $store,
        PermissionService $permissions,
    ): JsonResponse {
        abort_unless($permissions->can('sales.hold'), 403);

        $hold = $store->execute(
            $request->validated(),
            (string) $request->header('Idempotency-Key', ''),
        );

        return (new SaleHoldResource($hold))->response()->setStatusCode(201);
    }

    public function show(
        string $holdUlid,
        TenantContext $tenantContext,
        PermissionService $permissions,
    ): SaleHoldResource {
        abort_unless($permissions->can('sales.recall'), 403);

        return new SaleHoldResource($this->find($holdUlid, $tenantContext)->load([
            'customer',
            'salesmanParty',
            'branch',
            'warehouse',
            'items' => fn ($query) => $query->orderBy('sort_order'),
            'items.product.baseUnit',
            'items.product.secondaryUnit',
            'items.product.barcodes.unit',
            'items.product.prices',
            'items.unit',
            'items.saleScheme',
        ]));
    }

    public function destroy(
        string $holdUlid,
        TenantContext $tenantContext,
        AuditLogger $audit,
        PermissionService $permissions,
    ): JsonResponse {
        abort_unless($permissions->can('sales.hold'), 403);

        $hold = $this->find($holdUlid, $tenantContext);
        $ulid = $hold->ulid;

        $hold->delete();

        $audit->record('SALE_HOLD_DISCARDED', [
            'resource_type' => 'sale_hold',
            'resource_ulid' => $ulid,
        ]);

        return response()->json(['ok' => true]);
    }

    private function find(string $ulid, TenantContext $tenantContext): SaleHold
    {
        $hold = SaleHold::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('ulid', $ulid)
            ->first();

        if (! $hold) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $hold;
    }
}
