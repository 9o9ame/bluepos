<?php

namespace App\Http\Controllers\Api\Purchases;

use App\Actions\Purchases\CreatePurchaseOrderAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StorePurchaseOrderRequest;
use App\Http\Resources\Purchases\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = PurchaseOrder::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->with(['supplier', 'branch', 'warehouse'])
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('document_number', 'ilike', $term)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier
                        ->where('name', 'ilike', $term)
                        ->orWhere('code', 'ilike', $term));
            });
        }

        if ($request->filled('supplier_ulid')) {
            $query->whereHas('supplier', fn ($supplier) => $supplier
                ->where('ulid', (string) $request->string('supplier_ulid')));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', (string) $request->string('date_to'));
        }

        $page = $query->paginate($perPage);

        return [
            'data' => PurchaseOrderResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function store(
        StorePurchaseOrderRequest $request,
        CreatePurchaseOrderAction $create,
    ): JsonResponse {
        $this->authorize('create', PurchaseOrder::class);

        $order = $create->execute($request->validated());

        return (new PurchaseOrderResource($order))->response()->setStatusCode(201);
    }

    public function show(
        string $purchaseOrderUlid,
        TenantContext $tenantContext,
    ): PurchaseOrderResource {
        $order = PurchaseOrder::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('ulid', $purchaseOrderUlid)
            ->with(['supplier', 'branch', 'warehouse', 'lines.product', 'lines.unit'])
            ->first();

        if (! $order) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $this->authorize('view', $order);

        return new PurchaseOrderResource($order);
    }
}
