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

/**
 * Multi-product quantity general ledger. Reads immutable, signed stock movements.
 * The selected warehouse and branch always come from the authenticated context.
 */
final class ProductGlGridController extends Controller
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

        $movementTotals = DB::table('stock_movements')
            ->where('tenant_id', $context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('warehouse_id', $context->warehouseId())
            ->where('occurred_at', '<=', $to)
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN occurred_at < ? THEN quantity ELSE 0 END), 0) AS opening_qty,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND quantity > 0 THEN quantity ELSE 0 END), 0) AS debit_qty,
                 COALESCE(SUM(CASE WHEN occurred_at >= ? AND quantity < 0 THEN -quantity ELSE 0 END), 0) AS credit_qty,
                 COALESCE(SUM(quantity), 0) AS closing_qty",
                [$from, $from, $from],
            );

        $query = Product::query()
            ->forTenant($context->tenantId())
            ->joinSub($movementTotals, 'movement_summary', function ($join): void {
                $join->on('movement_summary.product_id', '=', 'products.id');
            })
            ->select([
                'products.ulid',
                'products.product_number',
                'products.sku',
                'products.name',
                'movement_summary.opening_qty',
                'movement_summary.debit_qty',
                'movement_summary.credit_qty',
                'movement_summary.closing_qty',
            ]);

        if (isset($input['q']) && trim($input['q']) !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], trim($input['q']));
            $term = '%'.$escaped.'%';
            $query->where(function ($match) use ($term): void {
                $match->where('products.name', 'ilike', $term)
                    ->orWhere('products.sku', 'ilike', $term)
                    ->orWhere('products.product_number', 'ilike', $term);
            });
        }

        $totals = DB::query()
            ->fromSub((clone $query)->reorder()->toBase(), 'results')
            ->selectRaw(
                'COALESCE(SUM(opening_qty),0) AS opening,
                 COALESCE(SUM(debit_qty),0) AS debit,
                 COALESCE(SUM(credit_qty),0) AS credit,
                 COALESCE(SUM(closing_qty),0) AS closing',
            )->first();

        $page = $query->orderBy('products.product_number')->orderBy('products.id')
            ->paginate((int) ($input['per_page'] ?? 100));

        return [
            'data' => collect($page->items())->map(fn (Product $product): array => [
                'product' => [
                    'ulid' => $product->ulid,
                    'product_number' => $product->product_number,
                    'sku' => $product->sku,
                    'name' => $product->name,
                ],
                'opening' => bcadd((string) $product->getAttribute('opening_qty'), '0', 6),
                'debit' => bcadd((string) $product->getAttribute('debit_qty'), '0', 6),
                'credit' => bcadd((string) $product->getAttribute('credit_qty'), '0', 6),
                'closing' => bcadd((string) $product->getAttribute('closing_qty'), '0', 6),
            ])->values()->all(),
            'meta' => [
                'from_date' => $input['from_date'],
                'to_date' => $input['to_date'],
                'warehouse' => [
                    'ulid' => $context->warehouse()->ulid,
                    'name' => $context->warehouse()->name,
                ],
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'totals' => [
                    'opening' => bcadd((string) ($totals?->opening ?? 0), '0', 6),
                    'debit' => bcadd((string) ($totals?->debit ?? 0), '0', 6),
                    'credit' => bcadd((string) ($totals?->credit ?? 0), '0', 6),
                    'closing' => bcadd((string) ($totals?->closing ?? 0), '0', 6),
                ],
            ],
        ];
    }
}
