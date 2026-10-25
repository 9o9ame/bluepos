<?php

namespace App\Http\Controllers\Api\Reports;

use App\Accounting\TrialBalanceReportBuilder;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AccountType;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeStatementReportController extends Controller
{
    public function __construct(
        private readonly TrialBalanceReportBuilder $trialBalance,
        private readonly PermissionService $permissions,
    ) {}

    public function preview(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }
        $data = $request->validate(['as_of_date' => ['required', 'date_format:Y-m-d']]);
        $report = $this->trialBalance->build(
            $context->tenantId(), $context->branchId(), $data['as_of_date']
        );
        $pnlLabels = AccountType::query()->forTenant($context->tenantId())
            ->get(['ulid', 'pnl_grouping_label'])->keyBy('ulid');

        $grouped = [];
        foreach ($report['groups'] as $head) {
            foreach ($head['rows'] as $row) {
                $main = strtoupper(trim((string) ($row['main_head']['name'] ?? '')));
                // Revenue and expense recognition must come from the posted chart of accounts.
                if (! in_array($main, ['REVENUES', 'EXPENSES'], true)) continue;
                $signed = bcsub((string) $row['credit'], (string) $row['debit'], 4);
                if (bccomp($signed, '0', 4) === 0) continue;
                $type = $pnlLabels->get($row['account_type']['ulid'] ?? '');
                $label = trim((string) ($type?->pnl_grouping_label ?? ''));
                $section = $label !== '' ? $label : ($row['account_type']['name'] ?? $main);
                $key = $main.'|'.$section;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = ['name' => $section, 'class' => $main,
                        'amount' => '0.0000', 'rows' => []];
                }
                $grouped[$key]['rows'][] = [
                    'account' => $row['account'],
                    'amount' => $signed,
                ];
                $grouped[$key]['amount'] = bcadd($grouped[$key]['amount'], $signed, 4);
            }
        }

        $net = '0.0000';
        $running = '0.0000';
        $sections = [];
        foreach ($grouped as $section) {
            $running = bcadd($running, $section['amount'], 4);
            $net = bcadd($net, $section['amount'], 4);
            $section['running_total'] = $running;
            $sections[] = $section;
        }
        return response()->json([
            'as_of_date' => $data['as_of_date'],
            'sections' => $sections,
            'net_income' => $net,
            'basis' => 'posted_ledger',
            'limitations' => [
                'Posted revenue and expense accounts only. FIFO closing inventory, unposted sales, and unconfigured COGS are not inferred.',
                'P&L group names use configured account type mappings; missing mappings fall back to account type names.',
            ],
        ]);
    }
    public function periodPreview(Request $request, TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $start = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $data['from']);
        $closing = $this->trialBalance->build($context->tenantId(), $context->branchId(), $data['to']);
        $opening = $this->trialBalance->build($context->tenantId(), $context->branchId(), $start->subDay()->toDateString());
        $openingBalances = [];
        foreach ($opening['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $openingBalances[$row['account']['ulid']] = $row['balance'];
            }
        }
        $labels = AccountType::query()->forTenant($context->tenantId())
            ->get(['ulid', 'pnl_grouping_label'])->keyBy('ulid');
        $groups = [];
        foreach ($closing['groups'] as $head) {
            foreach ($head['rows'] as $row) {
                $main = strtoupper(trim((string) ($row['main_head']['name'] ?? '')));
                if (! in_array($main, ['REVENUES', 'EXPENSES'], true)) continue;
                $previous = $openingBalances[$row['account']['ulid']] ?? '0.0000';
                $movement = bcsub((string) $row['balance'], (string) $previous, 4);
                $amount = bcmul($movement, '-1', 4);
                if (bccomp($amount, '0', 4) === 0) continue;
                $type = $labels->get($row['account_type']['ulid'] ?? '');
                $name = trim((string) ($type?->pnl_grouping_label ?? ''));
                $name = $name !== '' ? $name : ($row['account_type']['name'] ?? $main);
                $key = $main.'|'.$name;
                if (! isset($groups[$key])) {
                    $groups[$key] = ['name' => $name, 'class' => $main, 'amount' => '0.0000', 'rows' => []];
                }
                $groups[$key]['rows'][] = ['account' => $row['account'], 'amount' => $amount];
                $groups[$key]['amount'] = bcadd($groups[$key]['amount'], $amount, 4);
            }
        }
        $running = '0.0000';
        $sections = [];
        foreach ($groups as $section) {
            $running = bcadd($running, $section['amount'], 4);
            $section['running_total'] = $running;
            $sections[] = $section;
        }
        return response()->json([
            'from' => $data['from'], 'to' => $data['to'],
            'sections' => $sections, 'net_income' => $running,
            'basis' => 'posted_journal_period_movement',
            'limitations' => [
                'Period movement from posted P&L journals only; FIFO opening/ending stock and unconfigured COGS are not invented.',
                'Net Income is provisional until inventory valuation and sales accounting mappings are reconciled.',
            ],
        ]);
    }

}
