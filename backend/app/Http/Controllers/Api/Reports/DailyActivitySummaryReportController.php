<?php

namespace App\Http\Controllers\Api\Reports;

use App\Actions\Purchases\BuildPurchaseEffectiveSnapshotAction;
use App\Actions\Purchases\BuildPurchaseReturnEffectiveSnapshotAction;
use App\Actions\Sales\BuildSaleEffectiveSnapshotAction;
use App\Actions\Sales\BuildSaleReturnEffectiveSnapshotAction;
use App\Authz\PermissionService;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyActivitySummaryReportController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BuildSaleEffectiveSnapshotAction $saleSnapshots,
        private readonly BuildPurchaseEffectiveSnapshotAction $purchaseSnapshots,
        private readonly BuildSaleReturnEffectiveSnapshotAction $saleReturnSnapshots,
        private readonly BuildPurchaseReturnEffectiveSnapshotAction $purchaseReturnSnapshots,
    ) {}

    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')
            || ! $this->permissions->can('reports.inventory')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }

        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'])->endOfDay();
        if ($from->diffInDays($to) >= 31) {
            throw new ApiException('REPORT_DATE_RANGE_TOO_LARGE', 'Select a range of 31 days or less.', 422);
        }

        $tenantId = $context->tenantId();
        $branchId = $context->branchId();
        $warehouseId = $context->warehouseId();

        $sales = Sale::query()->forTenant($tenantId)
            ->where('branch_id', $branchId)->where('warehouse_id', $warehouseId)
            ->where('status', SaleStatus::Posted->value)
            ->whereBetween('sale_date', [$filters['from'], $filters['to']])
            ->with('latestRevision')->orderBy('sale_date')->orderBy('id')->get();

        $purchases = PurchaseInvoice::query()->forTenant($tenantId)
            ->where('branch_id', $branchId)->where('warehouse_id', $warehouseId)
            ->where('status', PurchaseInvoiceStatus::Posted->value)
            ->whereBetween('invoice_date', [$filters['from'], $filters['to']])
            ->with('latestRevision')->orderBy('invoice_date')->orderBy('id')->get();

        $saleReturns = SaleReturn::query()->forTenant($tenantId)
            ->where('branch_id', $branchId)->where('warehouse_id', $warehouseId)
            ->where('status', SaleReturnStatus::Posted->value)
            ->whereBetween('return_date', [$filters['from'], $filters['to']])
            ->orderBy('return_date')->orderBy('id')->get();

        $purchaseReturns = PurchaseReturn::query()->forTenant($tenantId)
            ->where('branch_id', $branchId)->where('warehouse_id', $warehouseId)
            ->where('status', PurchaseReturnStatus::Posted->value)
            ->whereBetween('return_date', [$filters['from'], $filters['to']])
            ->orderBy('return_date')->orderBy('id')->get();

        $documents = [];
        $totals = [
            'sale' => '0.0000', 'purchase' => '0.0000',
            'sale_return' => '0.0000', 'purchase_return' => '0.0000',
        ];
        foreach ($sales as $sale) {
            $effective = $this->saleSnapshots->current($sale);
            $amount = (string) ($effective['grand_total'] ?? $sale->grand_total);
            $documents[] = [
                'ulid' => $sale->ulid, 'type' => 'sale', 'date' => $sale->sale_date->toDateString(),
                'document_number' => $sale->document_number,
                'party' => (string) ($effective['customer']['name'] ?? $effective['customer_name'] ?? 'Walk-in'),
                'amount' => $amount,
                'lines' => $this->documentLines((array) ($effective['items'] ?? [])),
            ];
            $totals['sale'] = bcadd($totals['sale'], $amount, 4);
        }
        foreach ($purchases as $purchase) {
            $effective = $this->purchaseSnapshots->current($purchase);
            $amount = (string) ($effective['grand_total'] ?? $purchase->grand_total);
            $documents[] = [
                'ulid' => $purchase->ulid, 'type' => 'purchase', 'date' => $purchase->invoice_date->toDateString(),
                'document_number' => $purchase->document_number,
                'party' => (string) ($effective['supplier']['name'] ?? ''),
                'amount' => $amount,
                'lines' => $this->documentLines((array) ($effective['lines'] ?? [])),
            ];
            $totals['purchase'] = bcadd($totals['purchase'], $amount, 4);
        }
        foreach ($saleReturns as $returned) {
            $effective = $this->saleReturnSnapshots->current($returned);
            $amount = (string) ($effective['grand_total'] ?? $returned->grand_total);
            $documents[] = [
                'ulid' => $returned->ulid, 'type' => 'sale_return',
                'date' => $returned->return_date->toDateString(),
                'document_number' => $returned->document_number,
                'party' => (string) ($effective['customer']['name'] ?? ''),
                'amount' => $amount,
                'lines' => $this->documentLines((array) ($effective['lines'] ?? [])),
            ];
            $totals['sale_return'] = bcadd($totals['sale_return'], $amount, 4);
        }
        foreach ($purchaseReturns as $returned) {
            $effective = $this->purchaseReturnSnapshots->current($returned);
            $amount = (string) ($effective['grand_total'] ?? $returned->grand_total);
            $documents[] = [
                'ulid' => $returned->ulid, 'type' => 'purchase_return',
                'date' => $returned->return_date->toDateString(),
                'document_number' => $returned->document_number,
                'party' => (string) ($effective['supplier']['name'] ?? ''),
                'amount' => $amount,
                'lines' => $this->documentLines((array) ($effective['lines'] ?? [])),
            ];
            $totals['purchase_return'] = bcadd($totals['purchase_return'], $amount, 4);
        }
        usort($documents, static fn (array $a, array $b): int =>
            [$a['date'], $a['type'], $a['document_number']] <=> [$b['date'], $b['type'], $b['document_number']]);

        // The stock sheet reflects append-only movement quantities, not invoice item quantities.
        $stock = StockMovement::query()->forTenant($tenantId)
            ->where('branch_id', $branchId)->where('warehouse_id', $warehouseId)
            ->where('occurred_at', '<=', $to)
            ->with('product:id,ulid,name,product_number')
            ->orderBy('occurred_at')->orderBy('id')->get();

        $stockRows = [];
        foreach ($stock as $movement) {
            $product = $movement->product;
            if (! $product) {
                continue;
            }
            $key = $product->ulid;
            if (! isset($stockRows[$key])) {
                $stockRows[$key] = [
                    'product' => ['ulid' => $product->ulid, 'name' => $product->name,
                        'product_number' => $product->product_number],
                    'opening_quantity' => '0.000000',
                    'quantity_change' => '0.000000', 'closing_quantity' => '0.000000',
                    'movements' => [],
                ];
            }
            $quantity = (string) $movement->quantity;
            $stockRows[$key]['closing_quantity'] = bcadd($stockRows[$key]['closing_quantity'], $quantity, 6);
            if ($movement->occurred_at < $from) {
                $stockRows[$key]['opening_quantity'] = bcadd($stockRows[$key]['opening_quantity'], $quantity, 6);
                continue;
            }
            $type = $movement->movement_type->value;
            $stockRows[$key]['quantity_change'] = bcadd($stockRows[$key]['quantity_change'], $quantity, 6);
            $stockRows[$key]['movements'][$type] = bcadd(
                $stockRows[$key]['movements'][$type] ?? '0.000000', $quantity, 6
            );
        }

        return response()->json([
            'from' => $filters['from'], 'to' => $filters['to'],
            'business_name' => $context->tenant()->name,
            'documents' => $documents, 'totals' => $totals,
            'stock' => array_values($stockRows),
            'basis' => 'posted_documents_and_append_only_stock_movements',
            'limitations' => [
                'Document totals are gross, inclusive of configured taxes; they are not accounting revenue or profit.',
                'Stock movement grouping uses movement occurred_at; posted document dates may differ.',
                'Stock sheet opening and closing quantities are movement-derived, not cost valuations.',
            ],
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function documentLines(array $items): array
    {
        return collect($items)->map(static fn (array $line): array => [
            'ulid' => (string) ($line['ulid'] ?? ''),
            'product' => [
                'ulid' => (string) ($line['product']['ulid'] ?? ''),
                'name' => (string) ($line['product']['name'] ?? '—'),
                'product_number' => (string) ($line['product']['product_number'] ?? ''),
            ],
            'quantity' => (string) ($line['quantity'] ?? '0'),
            'base_quantity' => (string) ($line['stock_quantity'] ?? $line['base_quantity'] ?? '0'),
            'rate' => (string) ($line['unit_price'] ?? $line['unit_cost'] ?? '0'),
            'amount' => (string) ($line['line_total'] ?? '0'),
        ])->values()->all();
    }
}
