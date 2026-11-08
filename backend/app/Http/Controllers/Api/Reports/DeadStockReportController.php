<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DeadStockReportController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Dead stock means on-hand quantity > 0 and no outbound SALE movement
     * in the selected elapsed-day period. Returns do not count as sales.
     * This is NOT a no-movement report and does not infer unavailable sales.
     */
    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'q' => ['sometimes', 'string', 'max:180'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $days = (int) ($validated['days'] ?? 90);
        $cutoff = CarbonImmutable::now()->subDays($days)->startOfDay();
        $tenantId = $context->tenantId();
        $branchId = $context->branchId();
        $warehouseId = $context->warehouseId();
        $canViewCost = $this->permissions->can('inventory.view');

        $saleMovements = fn () => DB::table('stock_movements as dead_stock_sales')
            ->selectRaw('1')
            ->whereColumn('dead_stock_sales.product_id', 'stock_balances.product_id')
            ->where('dead_stock_sales.tenant_id', $tenantId)
            ->where('dead_stock_sales.branch_id', $branchId)
            ->where('dead_stock_sales.warehouse_id', $warehouseId)
            ->whereIn('dead_stock_sales.movement_type', ['sale', 'sale_amendment'])
            ->where('dead_stock_sales.quantity', '<', 0);

        $query = StockBalance::query()
            ->forTenant($tenantId)
            ->where('branch_id', $branchId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->whereHas('product', fn ($products) => $products->forTenant($tenantId)->where('is_active', true))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('stock_movements as dead_stock_sales')
                ->whereColumn('dead_stock_sales.product_id', 'stock_balances.product_id')
                ->where('dead_stock_sales.tenant_id', $tenantId)
                ->where('dead_stock_sales.branch_id', $branchId)
                ->where('dead_stock_sales.warehouse_id', $warehouseId)
                ->whereIn('dead_stock_sales.movement_type', ['sale', 'sale_amendment'])
                ->where('dead_stock_sales.quantity', '<', 0)
                ->where('dead_stock_sales.occurred_at', '>=', $cutoff))
            ->with(['product.category', 'product.brand'])
            ->orderBy('product_id');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($validated['q'])).'%';
            $query->whereHas('product', fn ($p) => $p->where(function ($search) use ($term) {
                $search->where('name', 'ilike', $term)->orWhere('sku', 'ilike', $term)
                    ->orWhere('product_number', 'ilike', $term);
            }));
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 100));
        $rows = collect($page->items())->map(function (StockBalance $balance) use ($tenantId, $branchId, $warehouseId, $canViewCost) {
            $product = $balance->product;
            $lastSale = DB::table('stock_movements')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $product->id)
                ->whereIn('movement_type', ['sale', 'sale_amendment'])
                ->where('quantity', '<', 0)
                ->max('occurred_at');

            return [
                'product' => [
                    'ulid' => $product->ulid,
                    'product_number' => $product->product_number,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'brand' => $product->brand?->name,
                ],
                'quantity' => bcadd((string) $balance->quantity, '0', 6),
                'last_sale_at' => $lastSale,
                'stock_value' => $canViewCost ? bcadd((string) $balance->stock_value, '0', 4) : null,
            ];
        })->values()->all();

        return [
            'data' => $rows,
            'meta' => [
                'days' => $days,
                'cutoff' => $cutoff->toDateString(),
                'definition' => 'Positive stock without an outbound posted sale in the preceding selected days.',
                'warehouse' => ['ulid' => $context->warehouse()->ulid, 'name' => $context->warehouse()->name],
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ];
    }
}
