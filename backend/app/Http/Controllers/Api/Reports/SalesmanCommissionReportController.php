<?php

namespace App\Http\Controllers\Api\Reports;

use App\Actions\Sales\BuildSaleEffectiveSnapshotAction;
use App\Actions\Sales\BuildSaleReturnEffectiveSnapshotAction;
use App\Authz\PermissionService;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Posted salesman sales and returns: source facts only. Payable commission,
 * percentages and per-piece rates stay NULL until real commission terms exist.
 */
final class SalesmanCommissionReportController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BuildSaleEffectiveSnapshotAction $saleSnapshots,
        private readonly BuildSaleReturnEffectiveSnapshotAction $returnSnapshots,
    ) {}

    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view sales reports.', 403);
        }

        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'salesman_ulid' => ['nullable', 'ulid', Rule::exists('party_profiles', 'ulid')
                ->where('tenant_id', $context->tenantId())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from_date']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to_date']);
        if ($from->diffInDays($to) > 30) {
            throw new ApiException('REPORT_DATE_RANGE_TOO_LARGE', 'Select at most 31 days.', 422);
        }

        // Select against the latest effective snapshot, not the original sale's
        // salesman FK: posted amendments can change the recorded assignment.
        $selectedSalesmanUlid = $data['salesman_ulid'] ?? null;

        $sources = [
            [
                Sale::query()->forTenant($context->tenantId())
                    ->where('branch_id', $context->branchId())
                    ->where('warehouse_id', $context->warehouseId())
                    ->where('status', SaleStatus::Posted->value)
                    ->whereBetween('sale_date', [$data['from_date'], $data['to_date']]),
                $this->saleSnapshots, 'sale', 'items', 'sale_date', '1',
            ],
            [
                SaleReturn::query()->forTenant($context->tenantId())
                    ->where('branch_id', $context->branchId())
                    ->where('warehouse_id', $context->warehouseId())
                    ->where('status', SaleReturnStatus::Posted->value)
                    ->whereBetween('return_date', [$data['from_date'], $data['to_date']]),
                $this->returnSnapshots, 'sale_return', 'lines', 'return_date', '-1',
            ],
        ];

        $documents = 0;
        $rows = [];
        foreach ($sources as [$query, $reader, $kind, $linesKey, $dateKey, $sign]) {
            $count = (clone $query)->limit(501)->count();
            $documents += $count;
            if ($documents > 500) {
                throw new ApiException('REPORT_TOO_LARGE', 'Select a shorter range (over 500 posted documents).', 422);
            }
            foreach ($query->with(['latestRevision', 'salesmanParty'])->orderBy('id')->get() as $document) {
                $snapshot = $reader->current($document);
                $salesman = $snapshot['salesman'] ?? null;
                if (! is_array($salesman) || empty($salesman['ulid'])
                    || ($selectedSalesmanUlid !== null && $salesman['ulid'] !== $selectedSalesmanUlid)) {
                    continue;
                }
                foreach ($snapshot[$linesKey] ?? [] as $line) {
                    $product = $line['product'] ?? null;
                    if (! is_array($product) || empty($product['ulid'])) {
                        continue;
                    }
                    $rawQuantity = (string) ($line['stock_quantity'] ?? $line['quantity'] ?? '0');
                    $rawAmount = bcsub(
                        (string) ($line['line_total'] ?? '0'),
                        (string) ($line['tax_amount'] ?? '0'), 4,
                    );
                    $quantity = bcmul($rawQuantity, $sign, 6);
                    $amount = bcmul($rawAmount, $sign, 4);
                    $rows[] = [
                        'document_ulid' => $document->ulid,
                        'line_ulid' => $line['ulid'] ?? null,
                        'document_number' => $snapshot['document_number'] ?? $document->document_number,
                        'document_type' => $kind,
                        'date' => $document->{$dateKey}?->toDateString(),
                        'salesman' => $salesman ? [
                            'ulid' => $salesman['ulid'] ?? null,
                            'name' => $salesman['name'] ?? null,
                        ] : null,
                        'customer' => $snapshot['customer']['name'] ?? $snapshot['customer_name'] ?? null,
                        'product' => [
                            'ulid' => $product['ulid'],
                            'product_number' => $product['product_number'] ?? null,
                            'name' => $product['name'] ?? null,
                        ],
                        'base_quantity' => $quantity,
                        'line_amount_ex_tax_before_invoice_discount' => $amount,
                        'cost_amount' => null,
                        'commission_percent' => null,
                        'commission_per_base_unit' => null,
                        'commission_amount' => null,
                        'commission_unavailable_reason' => 'No verified commission rate and basis is configured.',
                    ];
                }
            }
        }
        usort($rows, static fn (array $a, array $b) => [$a['date'], $a['document_number']]
            <=> [$b['date'], $b['document_number']]);

        $totals = ['base_quantity' => '0.000000', 'line_amount_ex_tax_before_invoice_discount' => '0.0000'];
        foreach ($rows as $row) {
            $totals['base_quantity'] = bcadd($totals['base_quantity'], $row['base_quantity'], 6);
            $totals['line_amount_ex_tax_before_invoice_discount'] = bcadd(
                $totals['line_amount_ex_tax_before_invoice_discount'],
                $row['line_amount_ex_tax_before_invoice_discount'], 4,
            );
        }

        $perPage = (int) ($data['per_page'] ?? 100);
        $page = max(1, (int) ($data['page'] ?? 1));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
                'warehouse' => [
                    'ulid' => $context->warehouse()->ulid, 'name' => $context->warehouse()->name,
                ],
                'total' => $total, 'totals' => $totals,
                'selected_salesman_ulid' => $selectedSalesmanUlid,
                'current_page' => $page,
                'last_page' => $lastPage, 'per_page' => $perPage,
                'commission_calculation_available' => false,
                'limitations' => [
                    'Line amounts exclude line tax but do not allocate invoice-level discounts.',
                    'Signed totals include posted sale returns; no commission payable is inferred.',
                    'Salesman selection uses the latest effective posted document snapshot.',
                    'At most 500 posted source documents can be processed per selected date range.',
                    'Costs, commission percentages, per-unit rates, and payable commissions are not available.',
                    'Source is posted effective sales/returns only, in the selected tenant, branch and warehouse.',
                ],
            ],
        ];
    }
}
