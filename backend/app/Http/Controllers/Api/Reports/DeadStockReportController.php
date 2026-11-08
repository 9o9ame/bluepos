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
     * Reference report uses last stock activity (including purchases/transfers).
     * Explicit sale-only mode preserves the earlier no-recent-sale definition.
     */
    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'activity_mode' => ['sometimes', 'in:any_activity,sale_only'],
            'q' => ['sometimes', 'string', 'max:180'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $days = (int) ($validated['days'] ?? 180);
        $mode = (string) ($validated['activity_mode'] ?? 'any_activity');
        $asOf = isset($validated['as_of'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['as_of'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $cutoff = $asOf->subDays($days)->startOfDay();
        $tenantId = $context->tenantId();
        $branchId = $context->branchId();
        $warehouseId = $context->warehouseId();
        $canViewCost = $this->permissions->can('inventory.view');

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
                ->when($mode === 'sale_only', fn ($query) => $query
                    ->whereIn('dead_stock_sales.movement_type', ['sale', 'sale_amendment'])
                    ->where('dead_stock_sales.quantity', '<', 0))
                ->whereBetween('dead_stock_sales.occurred_at', [$cutoff, $asOf->endOfDay()]))
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
        $rows = collect($page->items())->map(function (StockBalance $balance) use ($tenantId, $branchId, $warehouseId, $canViewCost, $mode, $asOf) {
            $product = $balance->product;
            $lastMovement = DB::table('stock_movements')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $product->id)
                ->when($mode === 'sale_only', fn ($query) => $query
                    ->whereIn('movement_type', ['sale', 'sale_amendment'])
                    ->where('quantity', '<', 0))
                ->where('occurred_at', '<=', $asOf->endOfDay())
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->first(['occurred_at', 'reference_type', 'reference_ulid']);

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
                'last_activity_at' => $lastMovement?->occurred_at,
                'last_transaction_type' => $lastMovement?->reference_type,
                'last_transaction_ulid' => $lastMovement?->reference_ulid,
                'days_inactive' => $lastMovement?->occurred_at
                    ? (int) CarbonImmutable::parse($lastMovement->occurred_at)->startOfDay()->diffInDays($asOf)
                    : null,
                'average_cost' => $canViewCost ? bcadd((string) ($balance->average_cost ?? '0'), '0', 4) : null,
                'stock_value' => $canViewCost ? bcadd((string) ($balance->stock_value ?? '0'), '0', 4) : null,
            ];
        })->values()->all();

        return [
            'data' => $rows,
            'meta' => [
                'days' => $days,
                'cutoff' => $cutoff->toDateString(),
                'as_of' => $asOf->toDateString(),
                'activity_mode' => $mode,
                'definition' => $mode === 'any_activity'
                    ? 'Positive stock without any posted stock movement in the selected elapsed-day period.'
                    : 'Positive stock without an outbound posted sale in the selected elapsed-day period.',
                'warehouse' => ['ulid' => $context->warehouse()->ulid, 'name' => $context->warehouse()->name],
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ];
    }
}
