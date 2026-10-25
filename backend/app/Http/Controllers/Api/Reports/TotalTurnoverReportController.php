<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AccountType;
use App\Models\BusinessSetting;
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
            'above' => ['sometimes', 'string', 'regex:/^\\d{1,16}(\\.\\d{1,4})?$/'],
            'period' => ['sometimes', 'in:this_month,previous_month,today,yesterday,custom'],
            'mode' => ['sometimes', 'in:detailed,simple'],
            'salesman_ulid' => ['prohibited'],
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

        // Manual parties require an explicit party role and AR/AP classification.
        // A ledger's name or lack of customer/supplier links is not sufficient.
        $manual = function ($q) use ($tenant) {
            $q->whereNull('a.customer_id')->whereNull('a.supplier_id')
                ->whereExists(fn ($profile) => $profile->selectRaw('1')
                    ->from('party_profiles as p')
                    ->join('party_profile_types as pt', 'pt.party_profile_id', '=', 'p.id')
                    ->whereColumn('p.id', 'a.party_profile_id')
                    ->where('p.tenant_id', $tenant)->where('pt.type', 'account'))
                ->whereExists(fn ($type) => $type->selectRaw('1')
                    ->from('account_types as t')->whereColumn('t.id', 'a.account_type_id')
                    ->where('t.tenant_id', $tenant)->where('t.is_cash', false)->where('t.is_bank', false)
                    ->where(fn ($flags) => $flags->where('t.is_receivable', true)->orWhere('t.is_payable', true)));
        };
        $settings = BusinessSetting::query()->forTenant($tenant)->first();
        $controls = collect(['opening_balance_equity_account_id', 'default_cash_account_id',
            'sales_clearing_account_id', 'purchase_clearing_account_id', 'sales_revenue_account_id',
            'sales_tax_payable_account_id', 'inventory_account_id', 'cogs_account_id'])
            ->map(fn ($field) => $settings?->getAttribute($field))->filter()->unique()->values()->all();
        // Work with a single party/account row; never join sales line items to
        // payments/journals (which would multiply money movements).
        $accounts = DB::table('accounts as a')
            ->where('a.tenant_id', $tenant)->where('a.is_active', true)
            ->whereIn('a.account_type_id', $types->pluck('id'))
            // A party turnover report must not include cash, tax, stock or
            // other internal ledgers simply because no type was selected.
            ->where(function ($q) use ($tenant, $manual) {
                $q->whereExists(fn ($linked) => $linked->selectRaw('1')
                    ->from('customers as c')
                    ->whereColumn('c.id', 'a.customer_id')
                    ->where('c.tenant_id', $tenant))
                  ->orWhereExists(fn ($linked) => $linked->selectRaw('1')
                    ->from('suppliers as s')
                    ->whereColumn('s.id', 'a.supplier_id')
                    ->where('s.tenant_id', $tenant))
                  ->orWhere($manual);
            })
            ->whereNotIn('a.id', $controls)
            ->when(($data['group'] ?? 'accounts') === 'accounts', fn ($q) => $q->where($manual))
            ->when(($data['group'] ?? 'accounts') === 'customers', fn ($q) => $q->whereNotNull('a.customer_id'))
            ->when(($data['group'] ?? 'accounts') === 'vendors', fn ($q) => $q->whereNotNull('a.supplier_id'))
            ->select('a.party_profile_id','a.id','a.name','a.customer_id','a.supplier_id','a.account_type_id')
            ->selectSub(DB::table('customers')->select('party_profile_id')
                ->whereColumn('id', 'a.customer_id')->where('tenant_id', $tenant), 'customer_profile_id')
            ->selectSub(DB::table('suppliers')->select('party_profile_id')
                ->whereColumn('id', 'a.supplier_id')->where('tenant_id', $tenant), 'supplier_profile_id')
            ->orderBy('a.name')->get();
        $ids = $accounts->pluck('id')->all();
        $balances = collect();
        $received = collect();
        $periodMovements = collect();
        $trading = collect();
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
            // Reference Simple Turn Over's signed Sales - Returns column follows
            // party ledger movement (which may be negative after recovery).
            // Keep posted invoice net sales separate for turnover thresholds.
            $periodMovements = (clone $ledger)
                ->whereDate('e.entry_date', '>=', $data['from'])
                ->groupBy('l.account_id')
                ->selectRaw('l.account_id, SUM(l.debit - l.credit)::numeric(20,4) as movement')
                ->get()->keyBy('account_id');
            // Net receipt credits and reversing debits. Returns, refunds and
            // payment vouchers are separate classifications.
            $received = (clone $ledger)->whereDate('e.entry_date', '>=', $data['from'])
                ->whereIn('e.document_type', ['receiving_voucher', 'sale_payment',
                    'receiving_voucher_reversal', 'sale_payment_reversal'])
                ->groupBy('l.account_id')
                ->selectRaw('l.account_id, SUM(l.credit - l.debit)::numeric(20,4) as received')
                ->get()->keyBy('account_id');
        }
        // Immutable headers omit amendments. Signed journals include corrections
        // on their accounting dates, with tax/discount matching grand_total.
        if ($ids !== []) {
            $trading = (clone $ledger)->whereDate('e.entry_date', '>=', $data['from'])
                ->groupBy('l.account_id')
                ->selectRaw("l.account_id,
                    SUM(CASE WHEN e.document_type IN ('sale_invoice', 'sale_invoice_amendment',
                        'sale_invoice_reversal', 'sale_invoice_amendment_reversal')
                        THEN l.debit - l.credit ELSE 0 END)::numeric(20,4) as sales,
                    SUM(CASE WHEN e.document_type IN ('sale_return', 'sale_return_amendment',
                        'sale_return_reversal', 'sale_return_amendment_reversal')
                        THEN l.credit - l.debit ELSE 0 END)::numeric(20,4) as returns,
                    SUM(CASE WHEN e.document_type IN ('purchase_invoice', 'purchase_invoice_amendment',
                        'purchase_invoice_reversal', 'purchase_invoice_amendment_reversal',
                        'purchase_return', 'purchase_return_amendment',
                        'purchase_return_reversal', 'purchase_return_amendment_reversal')
                        THEN l.credit - l.debit ELSE 0 END)::numeric(20,4) as purchases")
                ->get()->keyBy('account_id');
        }
        $above = (string) ($data['above'] ?? '0');
        $rows = $accounts->map(function ($a) use ($balances, $received, $periodMovements, $trading) {
            $grossSales = (string) ($trading->get($a->id)->sales ?? '0.0000');
            $returns = (string) ($trading->get($a->id)->returns ?? '0.0000');
            $purchases = (string) ($trading->get($a->id)->purchases ?? '0.0000');
            $profile = $a->party_profile_id ?? $a->customer_profile_id ?? $a->supplier_profile_id;
            return [
                '_party_key' => $profile !== null ? 'profile:'.$profile
                    : ($a->customer_id !== null ? 'customer:'.$a->customer_id
                        : ($a->supplier_id !== null ? 'supplier:'.$a->supplier_id : 'account:'.$a->id)),
                'name' => $a->name,
                'received' => (string) ($received->get($a->id)->received ?? '0.0000'),
                'sales_returns' => (string) ($periodMovements->get($a->id)->movement ?? '0.0000'),
                'net_sales' => bcsub($grossSales, $returns, 4),
                'receivable' => (string) ($balances->get($a->id)->balance ?? '0.0000'),
                'sales' => $grossSales,
                'purchases' => $purchases,
            ];
        })->groupBy('_party_key')->map(function ($partyAccounts) {
            // Aggregate selected account postings once per party.
            $first = $partyAccounts->first();
            foreach (['sales', 'net_sales', 'purchases'] as $field) {
                $first[$field] = $partyAccounts->reduce(
                    fn ($sum, $row) => bcadd($sum, $row[$field], 4), '0.0000');
            }
            $first['sales_returns'] = $partyAccounts->reduce(
                fn ($sum, $row) => bcadd($sum, $row['sales_returns'], 4), '0.0000');
            $first['received'] = $partyAccounts->reduce(
                fn ($sum, $row) => bcadd($sum, $row['received'], 4), '0.0000');
            $first['receivable'] = $partyAccounts->reduce(
                fn ($sum, $row) => bcadd($sum, $row['receivable'], 4), '0.0000');
            unset($first['_party_key']);
            return $first;
        })->values()->filter(function ($row) use ($above) {
            // "Show Turnover above" applies to period trading turnover, not
            // closing receivables or recoveries. With zero threshold, preserve
            // parties that have only outstanding balances or recoveries.
            $trading = bccomp(ltrim($row['net_sales'], '-'), $above, 4) > 0
                || bccomp(ltrim($row['purchases'], '-'), $above, 4) > 0;
            if (bccomp($above, '0', 4) > 0) {
                return $trading;
            }
            return $trading
                || bccomp(ltrim($row['sales_returns'], '-'), '0', 4) > 0
                || bccomp(ltrim($row['received'], '-'), '0', 4) > 0
                || bccomp(ltrim($row['receivable'], '-'), '0', 4) > 0;
        })->values();

        return response()->json([
            'from' => $data['from'], 'to' => $data['to'],
            'mode' => $data['mode'] ?? 'detailed',
            'rows' => $rows,
            'totals' => [
                'received' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['received'],4), '0.0000'),
                'sales_returns' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['sales_returns'],4), '0.0000'),
                'net_sales' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['net_sales'],4), '0.0000'),
                'receivable' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['receivable'],4), '0.0000'),
                'sales' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['sales'],4), '0.0000'),
                'purchases' => $rows->reduce(fn ($sum,$row) => bcadd($sum,$row['purchases'],4), '0.0000'),
            ],
        ]);
    }
}
