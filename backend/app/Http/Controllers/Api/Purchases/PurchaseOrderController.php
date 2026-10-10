<?php

namespace App\Http\Controllers\Api\Purchases;

use App\Actions\Purchases\CreatePurchaseOrderAction;
use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StorePurchaseOrderRequest;
use App\Http\Resources\Purchases\PurchaseOrderResource;
use App\Models\Product;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SaleItem;
use App\Models\StockBalance;
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

    public function status(
        Request $request,
        TenantContext $tenantContext,
    ): array {
        $this->authorize('viewAny', PurchaseOrder::class);

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'supplier_ulid' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'in:open,closed,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PurchaseOrderLine::query()
            ->forTenant($tenantContext->tenantId())
            ->whereHas('purchaseOrder', fn ($orders) => $orders
                ->forTenant($tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->where('warehouse_id', $tenantContext->warehouseId()))
            ->with([
                'purchaseOrder.supplier',
                'product',
                'unit',
            ])
            ->orderByDesc(
                PurchaseOrder::query()
                    ->select('order_date')
                    ->whereColumn('purchase_orders.id', 'purchase_order_lines.purchase_order_id')
                    ->limit(1),
            )
            ->orderByDesc('purchase_order_id')
            ->orderBy('id');

        if (! empty($data['date_from'])) {
            $query->whereHas('purchaseOrder', fn ($orders) => $orders
                ->whereDate('order_date', '>=', (string) $data['date_from']));
        }

        if (! empty($data['date_to'])) {
            $query->whereHas('purchaseOrder', fn ($orders) => $orders
                ->whereDate('order_date', '<=', (string) $data['date_to']));
        }

        if (! empty($data['supplier_ulid'])) {
            $query->whereHas('purchaseOrder.supplier', fn ($supplier) => $supplier
                ->where('ulid', (string) $data['supplier_ulid']));
        }

        if (! empty($data['status'])) {
            $query->whereHas('purchaseOrder', fn ($orders) => $orders
                ->where('status', (string) $data['status']));
        }

        $perPage = min(max((int) ($data['per_page'] ?? 50), 1), 100);
        $page = $query->paginate($perPage, ['*'], 'page', (int) ($data['page'] ?? 1));
        $lines = collect($page->items());
        $lineIds = $lines->pluck('id');

        $received = PurchaseInvoiceLine::query()
            ->whereIn('purchase_order_line_id', $lineIds)
            ->whereHas('purchaseInvoice', fn ($invoices) => $invoices
                ->forTenant($tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->where('warehouse_id', $tenantContext->warehouseId())
                ->where('status', PurchaseInvoiceStatus::Posted->value))
            ->selectRaw(
                'purchase_order_line_id, '
                .'COALESCE(SUM(base_quantity), 0) as received_base_quantity, '
                .'COALESCE(SUM(line_total), 0) as received_amount'
            )
            ->groupBy('purchase_order_line_id')
            ->get()
            ->keyBy('purchase_order_line_id');

        $rows = $lines->map(function (PurchaseOrderLine $line) use ($received): array {
            $receipt = $received->get($line->id);
            $receivedBase = bcadd((string) ($receipt?->received_base_quantity ?? '0'), '0', 6);
            $factor = bcadd((string) $line->conversion_factor, '0', 8);
            $receivedQty = bccomp($factor, '0', 8) === 1
                ? bcdiv($receivedBase, $factor, 6)
                : '0.000000';
            $remainingQty = bcsub((string) $line->quantity, $receivedQty, 6);
            if (bccomp($remainingQty, '0', 6) < 0) {
                $remainingQty = '0.000000';
            }

            $receivedAmount = bcadd((string) ($receipt?->received_amount ?? '0'), '0', 4);
            $balanceAmount = bcsub((string) $line->line_total, $receivedAmount, 4);

            $order = $line->purchaseOrder;

            return [
                'order' => [
                    'ulid' => $order->ulid,
                    'document_number' => $order->document_number,
                    'order_date' => $order->order_date?->toDateString(),
                    'status' => $order->status,
                ],
                'supplier' => $order->supplier ? [
                    'ulid' => $order->supplier->ulid,
                    'code' => $order->supplier->code,
                    'name' => $order->supplier->name,
                ] : null,
                'line_ulid' => $line->ulid,
                'product' => $line->product ? [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'name' => $line->product->name,
                ] : null,
                'unit' => $line->unit ? [
                    'ulid' => $line->unit->ulid,
                    'code' => $line->unit->code,
                    'name' => $line->unit->name,
                ] : null,
                'order_quantity' => bcadd((string) $line->quantity, '0', 6),
                'received_quantity' => $receivedQty,
                'remaining_quantity' => $remainingQty,
                'order_amount' => bcadd((string) $line->line_total, '0', 4),
                'received_amount' => $receivedAmount,
                'balance_amount' => $balanceAmount,
            ];
        })->values();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function generate(
        Request $request,
        TenantContext $tenantContext,
        TenantCatalog $catalog,
    ): array {
        $this->authorize('create', PurchaseOrder::class);

        $data = $request->validate([
            'mode' => ['required', 'in:last_n_days,between_dates,reorder_level,min_level,max_level,optimum_level,get_all'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'supplier_ulid' => ['nullable', 'string', 'size:26'],
            'category_ulid' => ['nullable', 'string', 'size:26'],
            'brand_ulid' => ['nullable', 'string', 'size:26'],
            'include_non_sold' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:20'],
        ]);

        $mode = (string) $data['mode'];

        if ($mode === 'optimum_level') {
            throw new ApiException(
                'VALIDATION_ERROR',
                'Optimum Level is not configured in the current product master.',
                422,
            );
        }

        if ($mode === 'last_n_days' && empty($data['days'])) {
            throw new ApiException('VALIDATION_ERROR', 'Days are required for Last N Days Sale.', 422);
        }

        if ($mode === 'between_dates' && (empty($data['date_from']) || empty($data['date_to']))) {
            throw new ApiException('VALIDATION_ERROR', 'From and To dates are required for Between Dates Sale.', 422);
        }

        $query = Product::query()
            ->forTenant($tenantContext->tenantId())
            ->where('status', ProductStatus::Active->value)
            ->where('is_active', true)
            ->with(['baseUnit', 'brand', 'category'])
            ->orderBy('product_number');

        if (! empty($data['supplier_ulid'])) {
            $supplier = $catalog->supplier((string) $data['supplier_ulid']);
            $query->whereHas('productSuppliers', fn ($links) => $links
                ->where('supplier_id', $supplier->id)
                ->where('is_active', true));
        }

        if (! empty($data['category_ulid'])) {
            $category = $catalog->category((string) $data['category_ulid']);
            $query->where('category_id', $category->id);
        }

        if (! empty($data['brand_ulid'])) {
            $brand = $catalog->brand((string) $data['brand_ulid']);
            $query->where('brand_id', $brand->id);
        }

        if ($mode === 'reorder_level') {
            $query->whereNotNull('reorder_level');
        } elseif ($mode === 'min_level') {
            $query->whereNotNull('minimum_stock');
        } elseif ($mode === 'max_level') {
            $query->whereNotNull('maximum_stock');
        }

        $perPage = min(max((int) ($data['per_page'] ?? 20), 10), 20);
        $page = $query->paginate($perPage, ['*'], 'page', (int) ($data['page'] ?? 1));
        $products = collect($page->items());
        $productIds = $products->pluck('id');

        $balances = StockBalance::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $consumption = collect();

        if (in_array($mode, ['last_n_days', 'between_dates'], true) && $productIds->isNotEmpty()) {
            $dateFrom = $mode === 'last_n_days'
                ? now()->subDays(((int) $data['days']) - 1)->toDateString()
                : (string) $data['date_from'];
            $dateTo = $mode === 'last_n_days'
                ? now()->toDateString()
                : (string) $data['date_to'];

            $consumption = SaleItem::query()
                ->forTenant($tenantContext->tenantId())
                ->whereIn('product_id', $productIds)
                ->whereHas('sale', fn ($sales) => $sales
                    ->where('branch_id', $tenantContext->branchId())
                    ->where('warehouse_id', $tenantContext->warehouseId())
                    ->where('status', SaleStatus::Posted->value)
                    ->whereDate('sale_date', '>=', $dateFrom)
                    ->whereDate('sale_date', '<=', $dateTo))
                ->selectRaw('product_id, COALESCE(SUM(stock_quantity), 0) as consumption')
                ->groupBy('product_id')
                ->pluck('consumption', 'product_id');
        }

        $includeNonSold = (bool) ($data['include_non_sold'] ?? false);

        $rows = $products
            ->map(function (Product $product) use ($mode, $balances, $consumption): array {
                $balance = $balances->get($product->id);
                $inStock = bcadd((string) ($balance?->quantity ?? '0'), '0', 6);
                $stockValue = bcadd((string) ($balance?->stock_value ?? '0'), '0', 4);
                $consumed = bcadd((string) ($consumption->get($product->id) ?? '0'), '0', 6);

                $target = match ($mode) {
                    'last_n_days', 'between_dates' => $consumed,
                    'reorder_level' => bcadd((string) ($product->reorder_level ?? '0'), '0', 6),
                    'min_level' => bcadd((string) ($product->minimum_stock ?? '0'), '0', 6),
                    'max_level' => bcadd((string) ($product->maximum_stock ?? '0'), '0', 6),
                    default => $inStock,
                };

                $difference = $mode === 'get_all'
                    ? '0.000000'
                    : bcsub($target, $inStock, 6);
                $suggested = bccomp($difference, '0', 6) === 1
                    ? $difference
                    : '0.000000';

                return [
                    'product' => [
                        'ulid' => $product->ulid,
                        'product_number' => $product->product_number,
                        'name' => $product->name,
                    ],
                    'unit' => $product->baseUnit ? [
                        'ulid' => $product->baseUnit->ulid,
                        'code' => $product->baseUnit->code,
                        'name' => $product->baseUnit->name,
                    ] : null,
                    'brand' => $product->brand ? [
                        'ulid' => $product->brand->ulid,
                        'name' => $product->brand->name,
                    ] : null,
                    'category' => $product->category ? [
                        'ulid' => $product->category->ulid,
                        'name' => $product->category->name,
                    ] : null,
                    'in_stock' => $inStock,
                    'stock_value' => $stockValue,
                    'consumption' => $consumed,
                    'difference' => $difference,
                    'suggested_quantity' => $suggested,
                    'unit_price' => bcadd((string) ($balance?->average_cost ?? '0'), '0', 4),
                ];
            })
            ->when(
                in_array($mode, ['last_n_days', 'between_dates'], true) && ! $includeNonSold,
                fn ($rows) => $rows->filter(fn ($row) => bccomp($row['consumption'], '0', 6) === 1),
            )
            ->values();

        return [
            'data' => $rows,
            'meta' => [
                'mode' => $mode,
                'count' => $rows->count(),
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'has_more' => $page->currentPage() < $page->lastPage(),
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
