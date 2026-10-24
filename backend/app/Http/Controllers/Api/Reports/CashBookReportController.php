<?php

namespace App\Http\Controllers\Api\Reports;

use App\Accounting\CashBookReportBuilder;
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
            'summary_only' => ['sometimes', 'boolean'],
            'group_by' => ['sometimes', 'in:none,date,document'],
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
