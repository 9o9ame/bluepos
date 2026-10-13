<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductLedgerController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantCatalog $catalog,
    ) {}

    public function __invoke(Request $request, TenantContext $tenantContext): array
    {
        if (! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view inventory reports.', 403);
        }

        $validated = $request->validate([
            'product_ulid' => ['required', 'string', 'max:26'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $product = $this->catalog->product((string) $validated['product_ulid']);
        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['from_date'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['to_date'])->endOfDay();
        $perPage = min(max((int) ($validated['per_page'] ?? 100), 1), 100);
        $canViewCost = $this->permissions->can('inventory.view');

        $base = StockMovement::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('product_id', $product->id);

        $openingQuantity = $this->sumQuantity(
            (clone $base)->where('occurred_at', '<', $from),
        );

        $periodQuery = (clone $base)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->orderBy('id');

        $periodTotals = $this->movementTotals(clone $periodQuery);
        $page = $periodQuery->paginate($perPage);

        $items = collect($page->items());
        $running = $openingQuantity;

        if ($items->isNotEmpty()) {
            /** @var StockMovement $first */
            $first = $items->first();

            $priorOnEarlierPages = $this->sumQuantity(
                (clone $base)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->where(function (Builder $query) use ($first): void {
                        $query
                            ->where('occurred_at', '<', $first->occurred_at)
                            ->orWhere(function (Builder $sameTime) use ($first): void {
                                $sameTime
                                    ->where('occurred_at', '=', $first->occurred_at)
                                    ->where('id', '<', $first->id);
                            });
                    }),
            );

            $running = bcadd($openingQuantity, $priorOnEarlierPages, 6);
        }

        $rows = $items->map(function (StockMovement $movement) use (&$running, $canViewCost): array {
            $quantity = bcadd((string) $movement->quantity, '0', 6);
            $running = bcadd($running, $quantity, 6);

            return [
                'ulid' => $movement->ulid,
                'occurred_at' => $movement->occurred_at?->toISOString(),
                'movement_type' => $movement->movement_type->value,
                'reference_type' => $movement->reference_type,
                'reference_ulid' => $movement->reference_ulid,
                'quantity_in' => bccomp($quantity, '0', 6) === 1 ? $quantity : '0.000000',
                'quantity_out' => bccomp($quantity, '0', 6) === -1 ? bcmul($quantity, '-1', 6) : '0.000000',
                'running_quantity' => $running,
                'unit_cost' => $canViewCost ? $movement->unit_cost : null,
                'total_cost' => $canViewCost ? $movement->total_cost : null,
                'notes' => $movement->notes,
            ];
        })->values()->all();

        return [
            'product' => [
                'ulid' => $product->ulid,
                'product_number' => $product->product_number,
                'sku' => $product->sku,
                'name' => $product->name,
            ],
            'warehouse' => [
                'ulid' => $tenantContext->warehouse()->ulid,
                'code' => $tenantContext->warehouse()->code,
                'name' => $tenantContext->warehouse()->name,
            ],
            'period' => [
                'from_date' => (string) $validated['from_date'],
                'to_date' => (string) $validated['to_date'],
            ],
            'opening_quantity' => $openingQuantity,
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'totals' => $periodTotals,
                'closing_quantity' => bcadd($openingQuantity, $periodTotals['net_quantity'], 6),
            ],
        ];
    }

    private function sumQuantity(Builder $query): string
    {
        return bcadd((string) $query->sum('quantity'), '0', 6);
    }

    /**
     * @return array{quantity_in: string, quantity_out: string, net_quantity: string}
     */
    private function movementTotals(Builder $query): array
    {
        $row = $query
            ->reorder()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) AS quantity_in,
                 COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) AS quantity_out,
                 COALESCE(SUM(quantity), 0) AS net_quantity",
            )
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'product_ulid' => 'Unable to calculate product ledger totals.',
            ]);
        }

        return [
            'quantity_in' => bcadd((string) $row->getAttribute('quantity_in'), '0', 6),
            'quantity_out' => bcadd((string) $row->getAttribute('quantity_out'), '0', 6),
            'net_quantity' => bcadd((string) $row->getAttribute('net_quantity'), '0', 6),
        ];
    }
}
