<?php

namespace App\Http\Controllers\Api\Reports;

use App\Actions\Sales\BuildSaleEffectiveSnapshotAction;
use App\Authz\PermissionService;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ProductDailyReportsController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BuildSaleEffectiveSnapshotAction $saleSnapshots,
    ) {}

    private function authorizeReport(): void
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }
    }

    public function options(TenantContext $context): JsonResponse
    {
        $this->authorizeReport();

        return response()->json([
            'categories' => Category::query()->forTenant($context->tenantId())
                ->where('is_active', true)->orderBy('name')->get(['ulid', 'name']),
            'brands' => Brand::query()->forTenant($context->tenantId())
                ->where('is_active', true)->orderBy('name')->get(['ulid', 'name']),
            'groupings' => ['category', 'brand'],
        ]);
    }

    public function stock(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $filters = $this->filters($request, $context);
        $products = $this->products($context, $filters);
        $stock = $this->stockFacts($context, $filters, $products);

        return response()->json([
            'from' => $filters['from'], 'to' => $filters['to'],
            'rows' => array_values($stock), 'basis' => 'append_only_stock_movements',
            'limitations' => [
                'Quantity is in base units. Opening plus signed period movements equals closing.',
                'Stock cost is based on movement cost snapshots, not current product purchase rates.',
                'Report is scoped to the currently selected branch and warehouse.',
            ],
        ]);
    }

    public function groups(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $filters = $this->filters($request, $context, true);
        $products = $this->products($context, $filters);
        $stock = $this->stockFacts($context, $filters, $products);
        $trade = $this->saleFacts($context, $filters, $products);
        $dimension = $filters['group_by'];
        $grouped = [];
        $totals = ['sale_amount' => '0.0000', 'sale_quantity' => '0.000000', 'stock_change' => '0.000000'];
        foreach ($products as $product) {
            $ulid = $dimension === 'brand' ? $product->brand?->ulid : $product->category?->ulid;
            $label = $dimension === 'brand' ? $product->brand?->name : $product->category?->name;
            $key = $ulid ?? 'unassigned';
            $amount = $trade[$product->id]['sale_amount'] ?? '0.0000';
            $qty = $trade[$product->id]['sale_quantity'] ?? '0.000000';
            $change = $stock[$product->id]['period_change'] ?? '0.000000';
            if (bccomp($amount, '0', 4) === 0 && bccomp($qty, '0', 6) === 0 && bccomp($change, '0', 6) === 0) {
                continue;
            }
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'ulid' => $ulid, 'name' => $label ?? 'Unassigned',
                    'sale_amount' => '0.0000', 'sale_quantity' => '0.000000',
                    'stock_change' => '0.000000', 'products' => 0,
                ];
            }
            $grouped[$key]['sale_amount'] = bcadd($grouped[$key]['sale_amount'], $amount, 4);
            $grouped[$key]['sale_quantity'] = bcadd($grouped[$key]['sale_quantity'], $qty, 6);
            $grouped[$key]['stock_change'] = bcadd($grouped[$key]['stock_change'], $change, 6);
            $grouped[$key]['products']++;
            $totals['sale_amount'] = bcadd($totals['sale_amount'], $amount, 4);
            $totals['sale_quantity'] = bcadd($totals['sale_quantity'], $qty, 6);
            $totals['stock_change'] = bcadd($totals['stock_change'], $change, 6);
        }
        $rows = array_values($grouped);
        usort($rows, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json([
            'from' => $filters['from'], 'to' => $filters['to'], 'group_by' => $dimension,
            'rows' => $rows, 'totals' => $totals,
            'basis' => 'posted_sales_and_returns_plus_stock_movements',
            'limitations' => [
                'Sale amounts exclude reported sales tax and account for invoice-level discounts.',
                'Stock changes include all movement types and are not sales quantities.',
                'Unassigned products form a separate group; company grouping uses the actual product brand.',
            ],
        ]);
    }

    public function profit(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $filters = $this->filters($request, $context);
        $products = $this->products($context, $filters);
        $stock = $this->stockFacts($context, $filters, $products);
        $trade = $this->saleFacts($context, $filters, $products);
        $rows = [];
        $totals = ['sale_amount' => '0.0000', 'sale_quantity' => '0.000000'];
        $costTotal = '0.0000';
        $allCostKnown = true;

        foreach ($products as $product) {
            $sale = $trade[$product->id] ?? null;
            if ($sale === null) continue;
            $cost = $stock[$product->id]['cost_of_sales'] ?? '0.0000';
            $costQty = $stock[$product->id]['cost_sale_quantity'] ?? '0.000000';
            $complete = ! ($stock[$product->id]['missing_cost'] ?? false)
                && ! $sale['amount_unavailable']
                && bccomp($costQty, $sale['sale_quantity'], 6) === 0;
            $profit = $complete ? bcsub($sale['sale_amount'], $cost, 4) : null;
            $rows[] = [
                'product' => $this->productData($product),
                'sale_quantity' => $sale['sale_quantity'],
                'sale_amount' => $sale['sale_amount'],
                'cost_amount' => $complete ? $cost : null,
                'gross_profit' => $profit,
                'margin_percent' => $profit !== null && bccomp($sale['sale_amount'], '0', 4) !== 0
                    ? bcmul(bcdiv($profit, $sale['sale_amount'], 8), '100', 4) : null,
                'cost_verified' => $complete,
            ];
            $totals['sale_quantity'] = bcadd($totals['sale_quantity'], $sale['sale_quantity'], 6);
            $totals['sale_amount'] = bcadd($totals['sale_amount'], $sale['sale_amount'], 4);
            if ($complete) $costTotal = bcadd($costTotal, $cost, 4);
            else $allCostKnown = false;
        }
        usort($rows, static fn ($a, $b) => strcmp($a['product']['name'], $b['product']['name']));
        $totals['cost_amount'] = $allCostKnown ? $costTotal : null;
        $totals['gross_profit'] = $allCostKnown ? bcsub($totals['sale_amount'], $costTotal, 4) : null;

        return response()->json([
            'from' => $filters['from'], 'to' => $filters['to'],
            'rows' => $rows, 'totals' => $totals,
            'basis' => 'posted_sales_and_returns_with_stock_cost_snapshots',
            'limitations' => [
                'Gross profit is operational product gross profit, not accounting net income.',
                'Missing cost snapshots or mismatched stock quantities result in unavailable profit, never estimated cost.',
                'Historical amendments and returned quantities are counted using their effective documents and stock movement snapshots.',
            ],
        ]);
    }

    private function filters(Request $request, TenantContext $context, bool $group = false): array
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_ulid' => ['nullable', 'string', 'ulid', Rule::exists('categories', 'ulid')
                ->where('tenant_id', $context->tenantId())],
            'brand_ulid' => ['nullable', 'string', 'ulid', Rule::exists('brands', 'ulid')
                ->where('tenant_id', $context->tenantId())],
            'group_by' => [$group ? 'required' : 'sometimes', Rule::in(['category', 'brand'])],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $filters['from']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', $filters['to']);
        if ($from->diffInDays($to) > 31) {
            throw new ApiException('REPORT_DATE_RANGE_TOO_LARGE', 'Select 31 days or less.', 422);
        }

        return $filters;
    }

    private function products(TenantContext $context, array $filters): Collection
    {
        return Product::query()->forTenant($context->tenantId())
            ->when(! empty($filters['category_ulid']), fn ($q) => $q->whereHas('category',
                fn ($c) => $c->where('ulid', $filters['category_ulid'])->where('tenant_id', $context->tenantId())))
            ->when(! empty($filters['brand_ulid']), fn ($q) => $q->whereHas('brand',
                fn ($b) => $b->where('ulid', $filters['brand_ulid'])->where('tenant_id', $context->tenantId())))
            ->with(['category:id,ulid,name', 'brand:id,ulid,name'])
            ->orderBy('name')->get();
    }

    private function productData(Product $product): array
    {
        return [
            'ulid' => $product->ulid, 'product_number' => $product->product_number,
            'name' => $product->name,
            'category' => $product->category ? ['ulid' => $product->category->ulid, 'name' => $product->category->name] : null,
            'brand' => $product->brand ? ['ulid' => $product->brand->ulid, 'name' => $product->brand->name] : null,
        ];
    }

    private function stockFacts(TenantContext $context, array $filters, Collection $products): array
    {
        $ids = $products->pluck('id')->all();
        if ($ids === []) return [];
        $end = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'])->endOfDay();
        $movements = StockMovement::query()->forTenant($context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->whereIn('product_id', $ids)->where('occurred_at', '<=', $end)
            ->orderBy('occurred_at')->orderBy('id')->get();
        $productMap = $products->keyBy('id');
        $rows = [];
        foreach ($movements as $m) {
            $id = $m->product_id;
            $product = $productMap->get($id);
            if (! $product) continue;
            if (! isset($rows[$id])) {
                $rows[$id] = [
                    'product' => $this->productData($product),
                    'opening_quantity' => '0.000000', 'period_change' => '0.000000',
                    'closing_quantity' => '0.000000', 'movements' => [],
                    'cost_of_sales' => '0.0000', 'cost_sale_quantity' => '0.000000',
                    'missing_cost' => false,
                ];
            }
            $qty = (string) $m->quantity;
            $date = $m->occurred_at->toDateString();
            $rows[$id]['closing_quantity'] = bcadd($rows[$id]['closing_quantity'], $qty, 6);
            if ($date < $filters['from']) {
                $rows[$id]['opening_quantity'] = bcadd($rows[$id]['opening_quantity'], $qty, 6);
                continue;
            }
            $rows[$id]['period_change'] = bcadd($rows[$id]['period_change'], $qty, 6);
            $type = $m->movement_type->value;
            $rows[$id]['movements'][$type] = bcadd($rows[$id]['movements'][$type] ?? '0.000000', $qty, 6);
            if (in_array($type, ['sale', 'sale_amendment', 'sale_return', 'sale_return_amendment'], true)) {
                $rows[$id]['cost_sale_quantity'] = bcsub($rows[$id]['cost_sale_quantity'], $qty, 6);
                if ($m->total_cost === null) $rows[$id]['missing_cost'] = true;
                else $rows[$id]['cost_of_sales'] = bcsub($rows[$id]['cost_of_sales'], (string) $m->total_cost, 4);
            }
        }
        return $rows;
    }

    private function saleFacts(TenantContext $context, array $filters, Collection $products): array
    {
        $productIds = $products->pluck('id')->all();
        if ($productIds === []) return [];
        $ulidToId = $products->pluck('id', 'ulid');
        $rows = [];
        $sales = Sale::query()->forTenant($context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('status', SaleStatus::Posted->value)
            ->whereBetween('sale_date', [$filters['from'], $filters['to']])
            ->with('latestRevision', 'items.product')
            ->orderBy('id')->get();

        foreach ($sales as $sale) {
            $snapshot = $this->saleSnapshots->current($sale);
            $items = collect($snapshot['items'] ?? [])
                ->filter(fn ($item) => ! empty($item['product']['ulid']) && $ulidToId->has($item['product']['ulid']))
                ->values();
            // Invoice discounts are allocated against all lines, not just selected products.
            $fullItems = collect($snapshot['items'] ?? []);
            $netLineTotal = '0.0000';
            foreach ($fullItems as $item) {
                $netLineTotal = bcadd($netLineTotal, bcsub(
                    (string) ($item['line_total'] ?? '0'), (string) ($item['tax_amount'] ?? '0'), 4
                ), 4);
            }
            $netRevenue = bcsub(
                bcsub((string) ($snapshot['subtotal'] ?? '0'), (string) ($snapshot['discount_amount'] ?? '0'), 4),
                (string) ($snapshot['invoice_discount_amount'] ?? '0'), 4
            );
            $difference = bcsub($netRevenue, $netLineTotal, 4);
            $reconcilable = bccomp($difference, '0', 4) === 0
                || (bccomp($netLineTotal, '0', 4) > 0 && bccomp($netRevenue, '0', 4) >= 0);
            $allocations = [];
            if ($reconcilable && bccomp($difference, '0', 4) !== 0) {
                $eligible = $fullItems->filter(fn ($item) => bccomp(bcsub(
                    (string) ($item['line_total'] ?? '0'), (string) ($item['tax_amount'] ?? '0'), 4
                ), '0', 4) > 0);
                $lastKey = $eligible->keys()->last();
                $remaining = $difference;
                foreach ($eligible as $key => $item) {
                    $base = bcsub((string) ($item['line_total'] ?? '0'), (string) ($item['tax_amount'] ?? '0'), 4);
                    $allocation = $key === $lastKey ? $remaining : bcdiv(
                        bcmul($difference, $base, 8), $netLineTotal, 4
                    );
                    $allocations[$item['ulid'] ?? (string) $key] = $allocation;
                    $remaining = bcsub($remaining, $allocation, 4);
                }
            }
            foreach ($items as $item) {
                $id = $ulidToId->get($item['product']['ulid']);
                if (! isset($rows[$id])) $rows[$id] = [
                    'sale_quantity' => '0.000000', 'sale_amount' => '0.0000', 'amount_unavailable' => false,
                ];
                $rows[$id]['sale_quantity'] = bcadd($rows[$id]['sale_quantity'], (string) ($item['stock_quantity'] ?? '0'), 6);
                $amount = bcsub((string) ($item['line_total'] ?? '0'), (string) ($item['tax_amount'] ?? '0'), 4);
                $amount = bcadd($amount, $allocations[$item['ulid'] ?? ''] ?? '0.0000', 4);
                $rows[$id]['sale_amount'] = bcadd($rows[$id]['sale_amount'], $amount, 4);
                if (! $reconcilable) $rows[$id]['amount_unavailable'] = true;
            }
        }

        $returns = SaleReturn::query()->forTenant($context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('status', SaleReturnStatus::Posted->value)
            ->whereBetween('return_date', [$filters['from'], $filters['to']])
            ->with('lines.product')->get();
        foreach ($returns as $return) {
            foreach ($return->lines as $line) {
                if (! in_array($line->product_id, $productIds, true)) continue;
                $id = $line->product_id;
                if (! isset($rows[$id])) $rows[$id] = [
                    'sale_quantity' => '0.000000', 'sale_amount' => '0.0000', 'amount_unavailable' => false,
                ];
                $rows[$id]['sale_quantity'] = bcsub($rows[$id]['sale_quantity'], (string) $line->stock_quantity, 6);
                $amount = bcsub((string) $line->line_total, (string) $line->tax_amount, 4);
                $rows[$id]['sale_amount'] = bcsub($rows[$id]['sale_amount'], $amount, 4);
            }
        }
        return $rows;
    }
}
