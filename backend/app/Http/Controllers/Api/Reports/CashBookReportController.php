<?php

namespace App\Http\Controllers\Api\Reports;

use App\Accounting\CashBookReportBuilder;
use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBookReportController extends Controller
{
    public function __construct(
        private readonly CashBookReportBuilder $builder,
        private readonly PermissionService $permissions,
    ) {}

    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }

        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'account_ulids' => ['sometimes', 'array', 'max:100'],
            'account_ulids.*' => ['required', 'string', 'ulid', 'distinct'],
            'include_bank' => ['sometimes', 'boolean'],
            'show_purchase' => ['sometimes', 'boolean'],
            'non_cash_also' => ['sometimes', 'boolean'],
            'summary_only' => ['sometimes', 'boolean'],
            'group_by' => ['sometimes', 'in:none,date,document,user'],
        ]);

        $accounts = $this->builder->cashAccounts($context->tenantId())
            ->filter(fn ($account) => (bool) ($data['include_bank'] ?? false)
                || (bool) ($account->accountType?->is_cash || $account->accountType?->code === '0010'))
            ->values();

        $selected = $data['account_ulids'] ?? [];
        if ($selected !== []) {
            $missing = array_diff($selected, $accounts->pluck('ulid')->all());
            if ($missing !== []) {
                throw new ApiException('ACCOUNT_NOT_FOUND', 'Selected cash account is unavailable.', 404);
            }
            $accounts = $accounts->whereIn('ulid', $selected)->values();
        }

        $reports = $accounts->map(fn ($account) => $this->builder->build(
            $account, $context->tenantId(), $context->branchId(),
            $data['start_date'], $data['end_date'],
        ))->values();

        $totals = ['opening' => '0.0000', 'received' => '0.0000', 'paid' => '0.0000', 'closing' => '0.0000'];
        foreach ($reports as $report) {
            foreach ($totals as $field => &$value) {
                $value = bcadd($value, $report['summary'][$field], 4);
            }
            unset($value);
        }

        $entries = collect($reports)->flatMap(fn ($report) => collect($report['rows'])
            ->map(fn ($row) => [...$row, 'account_ulid' => $report['account']['ulid'],
                'account_name' => $report['account']['name']]))
            ->sortBy([['date', 'asc'], ['line_ulid', 'asc']])->values();

        // Supplementary transactions never alter authoritative cash/bank balances.
        // Select one representative journal entry per non-cash posting to avoid
        // double-counting its debit and credit legs as separate cash events.
        $supplementary = collect();
        if (($data['show_purchase'] ?? false) || ($data['non_cash_also'] ?? false)) {
            $cashIds = $this->builder->cashAccounts($context->tenantId())->pluck('id')->all();
            $types = ['purchase_invoice', 'purchase_invoice_amendment',
                'purchase_return', 'purchase_return_amendment', 'purchase_payment'];
            $journalQuery = JournalEntry::query()
                ->where('tenant_id', $context->tenantId())
                ->where('branch_id', $context->branchId())
                ->where('status', JournalStatus::Posted->value)
                ->whereDate('entry_date', '>=', $data['start_date'])
                ->whereDate('entry_date', '<=', $data['end_date'])
                ->whereDoesntHave('lines', fn ($query) => $query
                    ->where('tenant_id', $context->tenantId())
                    ->whereIn('account_id', $cashIds));
            if (! ($data['non_cash_also'] ?? false)) {
                $journalQuery->whereIn('document_type', $types);
            }
            $supplementary = $journalQuery
                ->orderBy('entry_date')->orderBy('id')
                ->get(['ulid', 'entry_date', 'voucher_number', 'document_type', 'description'])
                ->map(fn ($entry) => [
                    'entry_ulid' => $entry->ulid,
                    'date' => $entry->entry_date?->format('Y-m-d'),
                    'trans_no' => $entry->voucher_number ?: '-',
                    'doc' => strtoupper(str_replace('_', ' ', $entry->document_type)),
                    'description' => $entry->description ?: '-',
                    'type' => 'non_cash',
                ])->values();
        }

        if ($data['summary_only'] ?? false) {
            $entries = collect();
        }

        return response()->json([
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'accounts' => $accounts->map(fn ($account) => [
                'ulid' => $account->ulid, 'code' => $account->code, 'name' => $account->name,
            ])->values(),
            'group_by' => $data['group_by'] ?? 'none',
            'totals' => $totals,
            'rows' => $entries->values(),
            'supplementary' => $supplementary,
        ]);
    }

    public function accounts(TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }

        return response()->json(['accounts' => $this->builder->cashAccounts($context->tenantId())
            ->map(fn ($account) => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'is_cash' => (bool) ($account->accountType?->is_cash || $account->accountType?->code === '0010'),
                'is_bank' => (bool) ($account->accountType?->is_bank || $account->accountType?->code === '0012'),
            ])->values()]);
    }
}
