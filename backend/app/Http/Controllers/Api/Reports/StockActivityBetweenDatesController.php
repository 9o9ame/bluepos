<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Signed stock activity from the existing posted stock movement history. */
final class StockActivityBetweenDatesController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }

        $input = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'q' => ['sometimes', 'string', 'max:180'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $input['from_date'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $input['to_date'])->endOfDay();
        $canViewCost = $this->permissions->can('inventory.view');

        $movements = DB::table('stock_movements')
            ->where('tenant_id', $context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('occurred_at', '<=', $to)
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN occurred_at < ? THEN quantity ELSE 0 END), 0) AS opening,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('purchase','purchase_amendment') THEN quantity ELSE 0 END), 0) AS purchases,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('purchase_return','purchase_return_amendment') THEN -quantity ELSE 0 END), 0) AS purchase_returns,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('sale','sale_amendment') THEN -quantity ELSE 0 END), 0) AS sales,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('sale_return','sale_return_amendment') THEN quantity ELSE 0 END), 0) AS sale_returns,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type NOT IN (
                   'purchase','purchase_amendment','purchase_return','purchase_return_amendment',
                   'sale','sale_amendment','sale_return','sale_return_amendment'
                 ) THEN quantity ELSE 0 END), 0) AS other_movements,
                 COALESCE(SUM(quantity), 0) AS closing,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('purchase','purchase_amendment') THEN total_cost ELSE 0 END), 0) AS purchase_cost,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('purchase_return','purchase_return_amendment') THEN total_cost ELSE 0 END), 0) AS purchase_return_cost,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('sale','sale_amendment') THEN total_cost ELSE 0 END), 0) AS sale_cost,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND movement_type IN ('sale_return','sale_return_amendment') THEN total_cost ELSE 0 END), 0) AS sale_return_cost,
                 COALESCE(SUM(total_cost), 0) AS closing_cost,
                 COALESCE(SUM(CASE WHEN total_cost IS NULL THEN 1 ELSE 0 END), 0) AS missing_cost_count",
                [$from, $from, $from, $from, $from, $from, $from, $from, $from, $from],
            );

        $query = Product::query()
            ->forTenant($context->tenantId())
            ->joinSub($movements, 'activity', fn ($join) => $join->on('activity.product_id', '=', 'products.id'))
            ->leftJoin('categories', function ($join) use ($context): void {
                $join->on('categories.id', '=', 'products.category_id')
                    ->where('categories.tenant_id', '=', $context->tenantId());
            })
            ->select([
                'products.ulid', 'products.product_number', 'products.sku', 'products.name',
                'categories.name AS category_name',
                'activity.opening', 'activity.purchases', 'activity.purchase_returns',
                'activity.sales', 'activity.sale_returns', 'activity.other_movements', 'activity.closing',
                'activity.purchase_cost', 'activity.purchase_return_cost', 'activity.sale_cost',
                'activity.sale_return_cost', 'activity.closing_cost', 'activity.missing_cost_count',
            ]);

        if (isset($input['q']) && trim($input['q']) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($input['q'])).'%';
            $query->where(fn ($q) => $q->where('products.name', 'ilike', $term)
                ->orWhere('products.sku', 'ilike', $term)
                ->orWhere('products.product_number', 'ilike', $term));
        }

        $columns = ['opening', 'purchases', 'purchase_returns', 'sales', 'sale_returns', 'other_movements', 'closing'];
        $selects = implode(', ', array_map(fn ($column) => 'COALESCE(SUM('.$column.'),0) AS '.$column, $columns));
        $totals = DB::query()->fromSub((clone $query)->reorder()->toBase(), 'filtered_products')
            ->selectRaw($selects)->first();
        $costColumns = ['purchase_cost', 'purchase_return_cost', 'sale_cost', 'sale_return_cost', 'closing_cost'];
        $costTotals = $canViewCost ? DB::query()
            ->fromSub((clone $query)->reorder()->toBase(), 'filtered_cost_products')
            ->selectRaw('COALESCE(SUM(missing_cost_count),0) AS missing_count, '
                .implode(', ', array_map(fn ($c) => 'COALESCE(SUM('.$c.'),0) AS '.$c, $costColumns)))
            ->first() : null;
        $page = $query->orderBy('products.product_number')->orderBy('products.id')
            ->paginate((int) ($input['per_page'] ?? 100));

        $rows = collect($page->items())->map(function (Product $product) use ($columns, $costColumns, $canViewCost): array {
            $row = ['product' => [
                'ulid' => $product->ulid,
                'product_number' => $product->product_number,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->getAttribute('category_name'),
            ]];
            foreach ($columns as $column) {
                $row[$column] = bcadd((string) $product->getAttribute($column), '0', 6);
            }
            foreach ($costColumns as $column) {
                $row[$column] = $canViewCost && (int) $product->getAttribute('missing_cost_count') === 0
                    ? bcadd((string) $product->getAttribute($column), '0', 4)
                    : null;
            }
            return $row;
        })->values()->all();

        $grand = [];
        foreach ($columns as $column) {
            $grand[$column] = bcadd((string) ($totals?->{$column} ?? '0'), '0', 6);
        }

        foreach ($costColumns as $column) {
            $grand[$column] = $canViewCost && (int) ($costTotals?->missing_count ?? 0) === 0
                ? bcadd((string) ($costTotals?->{$column} ?? '0'), '0', 4)
                : null;
        }

        return ['data' => $rows, 'meta' => [
            'from_date' => $input['from_date'], 'to_date' => $input['to_date'],
            'warehouse' => ['ulid' => $context->warehouse()->ulid, 'name' => $context->warehouse()->name],
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'total' => $page->total(), 'per_page' => $page->perPage(), 'totals' => $grand,
            'definition' => 'Signed posted quantities: closing = opening + purchases - purchase returns - sales + sale returns + other movements.',
            'cost_basis' => 'Cost columns are signed historical stock-movement costs, not sale revenue or invoice selling amounts; null when any movement cost is missing or the user lacks cost permission.',
        ]];
    }
}
