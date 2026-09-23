<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Catalog\TenantCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\StockBalanceResource;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', StockBalance::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = StockBalance::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['product', 'warehouse'])
            ->orderByDesc('updated_at');

        if ($request->filled('product_ulid')) {
            $product = $this->catalog->product((string) $request->string('product_ulid'));
            $query->where('product_id', $product->id);
        }

        if ($request->filled('warehouse_ulid')) {
            $warehouse = $this->catalog->warehouse((string) $request->string('warehouse_ulid'));
            $query->where('warehouse_id', $warehouse->id);
        }

        if ($request->filled('branch_ulid')) {
            $branchUlid = (string) $request->string('branch_ulid');
            $query->whereHas('warehouse.branch', fn ($q) => $q
                ->where('ulid', $branchUlid)
                ->where('tenant_id', $tenantContext->tenantId()));
        }

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->whereHas('product', function ($products) use ($term): void {
                $products->where('name', 'ilike', $term)
                    ->orWhere('product_number', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term);
            });
        }

        $page = $query->paginate($perPage);

        return [
            'data' => StockBalanceResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function forProduct(string $productUlid, TenantContext $tenantContext): array
    {
        $this->authorize('viewAny', StockBalance::class);

        $product = $this->catalog->product($productUlid);
        $balances = StockBalance::query()
            ->forTenant($tenantContext->tenantId())
            ->where('product_id', $product->id)
            ->with(['warehouse'])
            ->orderBy('warehouse_id')
            ->get();

        $total = '0.000000';
        foreach ($balances as $balance) {
            $total = bcadd($total, (string) $balance->quantity, 6);
        }

        $activeWarehouseId = $tenantContext->warehouseId();
        $active = $balances->firstWhere('warehouse_id', $activeWarehouseId);

        return [
            'product' => [
                'ulid' => $product->ulid,
                'product_number' => $product->product_number,
                'sku' => $product->sku,
                'name' => $product->name,
            ],
            'active_warehouse' => [
                'ulid' => $tenantContext->warehouse()->ulid,
                'code' => $tenantContext->warehouse()->code,
                'name' => $tenantContext->warehouse()->name,
                'quantity' => $active ? (string) $active->quantity : '0.000000',
            ],
            'total_quantity' => $total,
            'warehouses' => $balances->map(fn (StockBalance $balance) => [
                'warehouse' => [
                    'ulid' => $balance->warehouse->ulid,
                    'code' => $balance->warehouse->code,
                    'name' => $balance->warehouse->name,
                ],
                'quantity' => $balance->quantity,
                'average_cost' => $balance->average_cost,
                'stock_value' => $balance->stock_value,
            ])->values()->all(),
        ];
    }
}
