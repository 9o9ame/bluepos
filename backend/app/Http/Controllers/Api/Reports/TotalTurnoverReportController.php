<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AccountType;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TotalTurnoverReportController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    private function authorizeReport(): void
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'Accounting reports are not available.', 403);
        }
    }

    public function options(TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        return response()->json(['account_types' => AccountType::query()
            ->forTenant($context->tenantId())->where('is_active', true)
            ->orderBy('name')->get(['ulid','name'])->map(fn ($item) => [
                'ulid' => $item->ulid, 'name' => $item->name,
            ])->values()]);
    }

    public function report(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_type_ulids' => ['sometimes', 'array', 'max:100'],
            'account_type_ulids.*' => ['required', 'ulid', 'distinct'],
            'above' => ['sometimes', 'numeric', 'min:0'],
            'period' => ['sometimes', 'in:this_month,previous_month,today,yesterday,custom'],
            'mode' => ['sometimes', 'in:detailed,simple'],
            'group' => ['sometimes', 'in:accounts,all,customers,vendors'],
        ]);
        $tenant = $context->tenantId();
        $branch = $context->branchId();
        $types = AccountType::query()->forTenant($tenant)->where('is_active', true)
            ->when(! empty($data['account_type_ulids']),
                fn ($q) => $q->whereIn('ulid', $data['account_type_ulids']))
            ->get(['id','ulid']);
        if (! empty($data['account_type_ulids']) && $types->count() !== count($data['account_type_ulids'])) {
            throw new ApiException('INVALID_ACCOUNT_TYPE', 'Selected account type unavailable.', 404);
        }

        // Work with a single party/account row; never join sales line items to
        // payments/journals (which would multiply money movements).
        $accounts = DB::table('accounts as a')
            ->where('a.tenant_id', $tenant)->where('a.is_active', true)
            ->whereIn('a.account_type_id', $types->pluck('id'))
            // A party turnover report must not include cash, tax, stock or
            // other internal ledgers simply because no type was selected.
            ->where(fn ($q) => $q->whereNotNull('a.customer_id')
                ->orWhereNotNull('a.supplier_id'))
            ->when(($data['group'] ?? 'accounts') === 'customers', fn ($q) => $q->whereNotNull('a.customer_id'))
            ->when(($data['group'] ?? 'accounts') === 'vendors', fn ($q) => $q->whereNotNull('a.supplier_id'))
            ->select('a.id','a.name','a.customer_id','a.supplier_id','a.account_type_id')
            ->orderBy('a.name')->get();
        $ids = $accounts->pluck('id')->all();
        $balances = collect();
        $received = collect();
        $periodMovements = collect();
        if ($ids !== []) {
            $ledger = DB::table('journal_lines as l')
                ->join('journal_entries as e', function ($join) use ($tenant, $branch) {
                    $join->on('e.id', '=', 'l.journal_entry_id')
                        ->where('e.tenant_id', $tenant)->where('e.branch_id', $branch)
                        ->where('e.status', 'posted');
                })
                ->where('l.tenant_id', $tenant)->whereIn('l.account_id', $ids)
                ->whereDate('e.entry_date', '<=', $data['to']);
            $balances = (clone $ledger)->groupBy('l.account_id')
                ->selectRaw('l.account_id, SUM(l.debit - l.credit)::numeric(20,4) as balance')
                ->get()->keyBy('account_id');
            $periodMovements = (clone $ledger)
                ->whereDate('e.entry_date', '>=', $data['from'])
                ->groupBy('l.account_id')
                ->selectRaw('l.account_id, SUM(l.debit - l.credit)::numeric(20,4) as movement')
                ->get()->keyBy('account_id');
            // Recovery is a credit posted to a linked party account during
            // the selected period. Do not count sale invoice credits as receipts.
            $received = (clone $ledger)->whereDate('e.entry_date', '>=', $data['from'])
                ->whereIn('e.document_type', ['receiving_voucher', 'sale_payment'])
                ->groupBy('l.account_id')
                ->selectRaw('l.account_id, SUM(l.credit)::numeric(20,4) as received')
                ->get()->keyBy('account_id');
        }
        $customerIds = $accounts->pluck('customer_id')->filter()->unique()->all();
        $supplierIds = $accounts->pluck('supplier_id')->filter()->unique()->all();
        $sales = $customerIds ? DB::table('sales')
            ->where('tenant_id',$tenant)->where('branch_id',$branch)->where('status','posted')
            ->whereBetween('sale_date', [$data['from'],$data['to']])
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(grand_total)::numeric(20,4) as total')
            ->get()->keyBy('customer_id') : collect();
        $purchases = $supplierIds ? DB::table('purchase_invoices')
            ->where('tenant_id',$tenant)->where('branch_id',$branch)->where('status','posted')
            ->whereBetween('invoice_date', [$data['from'],$data['to']])
            ->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(grand_total)::numeric(20,4) as total')
            ->get()->keyBy('supplier_id') : collect();
        $above = (string) ($data['above'] ?? '0');
        $rows = $accounts->map(function ($a) use ($balances, $received, $periodMovements, $sales, $purchases) {
            return [
                'name' => $a->name,
                'received' => (string) ($received->get($a->id)->received ?? '0.0000'),
                'sales_returns' => (string) ($periodMovements->get($a->id)->movement ?? '0.0000'),
                'receivable' => (string) ($balances->get($a->id)->balance ?? '0.0000'),
                'sales' => (string) ($sales->get($a->customer_id)->total ?? '0.0000'),
                'purchases' => (string) ($purchases->get($a->supplier_id)->total ?? '0.0000'),
            ];
        })->filter(fn ($row) => bccomp(ltrim($row['sales_returns'], '-'), $above, 4) > 0
            || bccomp($row['received'], $above, 4) > 0
            || bccomp($row['sales'], $above, 4) > 0
            || bccomp($row['purchases'], $above, 4) > 0
            || bccomp(ltrim($row['receivable'], '-'), $above, 4) > 0)->values();

        return response()->json([
            'from' => $data['from'], 'to' => $data['to'],
            'mode' => $data['mode'] ?? 'detailed',
            'rows' => $rows,
            'totals' => [
                'received' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['received'],4), '0.0000'),
                'sales_returns' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['sales_returns'],4), '0.0000'),
                'receivable' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['receivable'],4), '0.0000'),
                'sales' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['sales'],4), '0.0000'),
                'purchases' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['purchases'],4), '0.0000'),
            ],
        ]);
    }
}
