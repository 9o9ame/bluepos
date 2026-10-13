<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductStockStatusController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function __invoke(Request $request, TenantContext $tenantContext): array
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }

        $perPage = min(max($request->integer('per_page', 50), 1), 100);
        $query = $this->query($request, $tenantContext);
        $canViewCost = $this->permissions->can('inventory.view');

        $totals = [
            'quantity' => '0.000000',
            'stock_value' => $canViewCost ? '0.0000' : null,
            'retail_stock_value' => '0.0000',
        ];

        (clone $query)
            ->reorder('products.id')
            ->chunk(500, function ($products) use (&$totals, $canViewCost): void {
                foreach ($products as $product) {
                    $balance = $product->stockBalances->first();
                    $quantity = bcadd((string) ($balance?->quantity ?? '0'), '0', 6);
                    $retail = $product->prices->first(
                        fn ($price) => $price->is_active && $price->price_type->value === 'retail',
                    )?->amount;

                    $totals['quantity'] = bcadd($totals['quantity'], $quantity, 6);

                    if ($canViewCost) {
                        $totals['stock_value'] = bcadd(
                            (string) $totals['stock_value'],
                            (string) ($balance?->stock_value ?? '0'),
                            4,
                        );
                    }

                    if ($retail !== null) {
                        $totals['retail_stock_value'] = bcadd(
                            $totals['retail_stock_value'],
                            bcmul($quantity, (string) $retail, 4),
                            4,
                        );
                    }
                }
            });

        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())
                ->map(fn (Product $product) => $this->row($product, $canViewCost))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'warehouse' => [
                    'ulid' => $tenantContext->warehouse()->ulid,
                    'code' => $tenantContext->warehouse()->code,
                    'name' => $tenantContext->warehouse()->name,
                ],
                'totals' => $totals,
            ],
        ];
    }

    /**
     * @return Builder<Product>
     */
    private function query(Request $request, TenantContext $tenantContext): Builder
    {
        $query = Product::query()
            ->forTenant($tenantContext->tenantId())
            ->with([
                'category',
                'brand',
                'prices' => fn ($prices) => $prices
                    ->where('is_active', true)
                    ->where('price_type', 'retail'),
                'stockBalances' => fn ($balances) => $balances
                    ->where('tenant_id', $tenantContext->tenantId())
                    ->where('branch_id', $tenantContext->branchId())
                    ->where('warehouse_id', $tenantContext->warehouseId()),
            ])
            ->orderBy('product_number');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($products) use ($term): void {
                $products->where('name', 'ilike', $term)
                    ->orWhere('product_number', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term)
                    ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('barcode', 'ilike', $term));
            });
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('with_balance')) {
            $query->whereHas('stockBalances', fn ($balances) => $balances
                ->where('tenant_id', $tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->where('warehouse_id', $tenantContext->warehouseId())
                ->where('quantity', '!=', 0));
        }

        if ($request->boolean('low_stock')) {
            $query
                ->whereNotNull('reorder_level')
                ->where(function ($products) use ($tenantContext): void {
                    $products
                        ->whereDoesntHave('stockBalances', fn ($balances) => $balances
                            ->where('tenant_id', $tenantContext->tenantId())
                            ->where('branch_id', $tenantContext->branchId())
                            ->where('warehouse_id', $tenantContext->warehouseId()))
                        ->orWhereHas('stockBalances', fn ($balances) => $balances
                            ->where('tenant_id', $tenantContext->tenantId())
                            ->where('branch_id', $tenantContext->branchId())
                            ->where('warehouse_id', $tenantContext->warehouseId())
                            ->whereColumn('stock_balances.quantity', '<=', 'products.reorder_level'));
                });
        }

        return $query;
    }

    private function row(Product $product, bool $canViewCost): array
    {
        /** @var StockBalance|null $balance */
        $balance = $product->stockBalances->first();
        $quantity = bcadd((string) ($balance?->quantity ?? '0'), '0', 6);
        $retail = $product->prices->first()?->amount;

        return [
            'product' => [
                'ulid' => $product->ulid,
                'product_number' => $product->product_number,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category ? [
                    'ulid' => $product->category->ulid,
                    'name' => $product->category->name,
                ] : null,
                'brand' => $product->brand ? [
                    'ulid' => $product->brand->ulid,
                    'name' => $product->brand->name,
                ] : null,
            ],
            'quantity' => $quantity,
            'reorder_level' => $product->reorder_level,
            'average_cost' => $canViewCost ? $balance?->average_cost : null,
            'stock_value' => $canViewCost ? $balance?->stock_value : null,
            'retail_rate' => $retail,
            'retail_stock_value' => $retail !== null
                ? bcmul($quantity, (string) $retail, 4)
                : null,
        ];
    }
}
