<?php

namespace App\Http\Controllers\Api\Reports;

use App\Actions\Purchases\BuildPurchaseEffectiveSnapshotAction;
use App\Actions\Sales\BuildSaleEffectiveSnapshotAction;
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
    ) {}

    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }

        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'])->endOfDay();
        if ($from->diffInDays($to) > 31) {
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
            ];
            $totals['purchase'] = bcadd($totals['purchase'], $amount, 4);
        }
        foreach ($saleReturns as $returned) {
            $documents[] = [
                'ulid' => $returned->ulid, 'type' => 'sale_return',
                'date' => $returned->return_date->toDateString(),
                'document_number' => $returned->document_number,
                'party' => '', 'amount' => (string) $returned->grand_total,
            ];
            $totals['sale_return'] = bcadd($totals['sale_return'], (string) $returned->grand_total, 4);
        }
        foreach ($purchaseReturns as $returned) {
            $documents[] = [
                'ulid' => $returned->ulid, 'type' => 'purchase_return',
                'date' => $returned->return_date->toDateString(),
                'document_number' => $returned->document_number,
                'party' => '', 'amount' => (string) $returned->grand_total,
            ];
            $totals['purchase_return'] = bcadd($totals['purchase_return'], (string) $returned->grand_total, 4);
        }
        usort($documents, static fn (array $a, array $b): int =>
            [$a['date'], $a['type'], $a['document_number']] <=> [$b['date'], $b['type'], $b['document_number']]);

        // The stock sheet reflects append-only movement quantities, not invoice item quantities.
        $stock = StockMovement::query()->forTenant($tenantId)
            ->where('branch_id', $branchId)->where('warehouse_id', $warehouseId)
            ->whereBetween('occurred_at', [$from, $to])
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
                    'quantity_change' => '0.000000', 'movements' => [],
                ];
            }
            $quantity = (string) $movement->quantity;
            $type = $movement->movement_type->value;
            $stockRows[$key]['quantity_change'] = bcadd($stockRows[$key]['quantity_change'], $quantity, 6);
            $stockRows[$key]['movements'][$type] = bcadd(
                $stockRows[$key]['movements'][$type] ?? '0.000000', $quantity, 6
            );
        }

        return response()->json([
            'from' => $filters['from'], 'to' => $filters['to'],
            'documents' => $documents, 'totals' => $totals,
            'stock' => array_values($stockRows),
            'basis' => 'posted_documents_and_append_only_stock_movements',
            'limitations' => [
                'Document totals are gross, inclusive of configured taxes; they are not accounting revenue or profit.',
                'Stock movement grouping uses movement occurred_at; posted document dates may differ.',
                'Stock sheet shows period quantity changes, not opening/closing stock valuations.',
            ],
        ]);
    }
}
