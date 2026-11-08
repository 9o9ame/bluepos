<?php

namespace App\Http\Controllers\Api\Reports;

use App\Accounting\AccountBalanceCalculator;
use App\Authz\PermissionService;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * As-of posted party ledger balances. This report never creates or modifies
 * party leaf accounts. Current/future due remains unavailable without
 * invoice-level due dates and settlement allocation.
 */
final class OutstandingBalancesReportController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AccountBalanceCalculator $calculator,
    ) {}

    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }

        $input = $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d'],
            'party_type' => ['required', Rule::in(['customer', 'vendor'])],
            'mode' => ['sometimes', Rule::in(['address', 'contact', 'current_future'])],
            'q' => ['sometimes', 'string', 'max:180'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $type = $input['party_type'];
        $canViewParty = $type === 'customer'
            ? $this->permissions->can('customers.view') || $this->permissions->can('customers.manage')
            : $this->permissions->can('suppliers.view') || $this->permissions->can('suppliers.manage');

        if (! $canViewParty) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view party details.', 403);
        }

        $query = Account::query()
            ->forTenant($context->tenantId())
            ->whereNotNull($type === 'customer' ? 'customer_id' : 'supplier_id')
            ->with(['accountType', 'customer', 'supplier'])
            ->orderBy('name')->orderBy('id');

        if (isset($input['q']) && trim($input['q']) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($input['q'])).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)
                ->orWhere('code', 'ilike', $term));
        }

        $page = $query->paginate((int) ($input['per_page'] ?? 100));
        $ids = collect($page->items())->pluck('id')->all();
        $balances = $ids === [] ? collect() : DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $context->tenantId())
            ->where('journal_entries.tenant_id', $context->tenantId())
            ->where('journal_entries.branch_id', $context->branchId())
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->where('journal_entries.entry_date', '<=', $input['as_of'])
            ->whereIn('journal_lines.account_id', $ids)
            ->selectRaw(
                'journal_lines.account_id, COALESCE(SUM(journal_lines.debit),0) AS debit,
                 COALESCE(SUM(journal_lines.credit),0) AS credit',
            )->groupBy('journal_lines.account_id')->get()->keyBy('account_id');

        $lastSales = collect();
        if ($type === 'customer') {
            $customerIds = collect($page->items())->pluck('customer_id')->filter()->all();
            if ($customerIds !== []) {
                $lastSales = DB::table('sales')
                    ->where('tenant_id', $context->tenantId())
                    ->where('branch_id', $context->branchId())
                    ->where('status', 'posted')
                    ->where('sale_date', '<=', $input['as_of'])
                    ->whereIn('customer_id', $customerIds)
                    ->selectRaw('customer_id, MAX(sale_date) AS last_sale_date')
                    ->groupBy('customer_id')->get()->keyBy('customer_id');
            }
        }

        $pageTotal = '0.0000';
        $rows = collect($page->items())->map(function (Account $account) use ($balances, $lastSales, $type, &$pageTotal): array {
            $party = $type === 'customer' ? $account->customer : $account->supplier;
            $balance = $balances->get($account->id);
            $signed = $this->calculator->money($this->calculator->signedEffect(
                $account, (string) ($balance?->debit ?? '0'), (string) ($balance?->credit ?? '0'),
            ));
            $pageTotal = bcadd($pageTotal, $signed, 4);

            return [
                'account_ulid' => $account->ulid,
                'party_ulid' => $party?->ulid,
                'code' => $party?->code ?? $account->code,
                'name' => $party?->name ?? $account->name,
                'address' => $party?->address ?? $account->address,
                'mobile' => $party?->mobile,
                'phone' => $party?->phone,
                'ntn' => $party?->ntn ?? $account->ntn,
                'license_number' => $party?->license_number,
                'license_expires_on' => $party?->license_expires_on?->format('Y-m-d'),
                'last_sale_date' => $type === 'customer'
                    ? $lastSales->get($account->customer_id)?->last_sale_date : null,
                'balance' => $signed,
                'current_due' => null,
                'future_due' => null,
                'due_unavailable_reason' => 'No verified invoice-level due-date allocation is available.',
            ];
        })->values()->all();

        return ['data' => $rows, 'meta' => [
            'as_of' => $input['as_of'],
            'party_type' => $type,
            'mode' => $input['mode'] ?? 'address',
            'branch_ulid' => $context->branch()->ulid,
            'page_balance_total' => $pageTotal,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'limitations' => [
                'Balances use posted journal lines for the selected branch up to the as-of date.',
                'Current and future due are unavailable until due-date and settlement allocations are verified.',
                'Page balance total is limited to the displayed page, not all parties.',
            ],
        ]];
    }
}
