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

class BalanceSheetReportController extends Controller
{
    public function __construct(
        private readonly TrialBalanceReportBuilder $trialBalance,
        private readonly PermissionService $permissions,
    ) {}

    private function authorizeReport(): void
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }
    }

    public function options(TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        return response()->json([
            'account_types' => AccountType::query()->forTenant($context->tenantId())
                ->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['ulid', 'name']),
        ]);
    }

    public function preview(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $data = $request->validate([
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'summarized' => ['sometimes', 'boolean'],
            'arrange_by_ids' => ['sometimes', 'boolean'],
            'account_type_ulids' => ['sometimes', 'array', 'max:200'],
            'account_type_ulids.*' => ['required', 'ulid', 'distinct'],
            'party_groups' => ['sometimes', 'array', 'max:4'],
            'party_groups.*' => ['required', 'in:accounts,all,customers,vendors', 'distinct'],
        ]);
        $types = AccountType::query()->forTenant($context->tenantId())->where('is_active', true)
            ->when(! empty($data['account_type_ulids']), fn ($query) => $query->whereIn('ulid', $data['account_type_ulids']))
            ->pluck('ulid')->all();
        if (! empty($data['account_type_ulids']) && count($types) !== count($data['account_type_ulids'])) {
            throw new ApiException('ACCOUNT_TYPE_NOT_FOUND', 'Selected account type is unavailable.', 404);
        }

        $report = $this->trialBalance->build($context->tenantId(), $context->branchId(), $data['as_of_date']);
        $allowedGroups = $data['party_groups'] ?? [];
        $all = $allowedGroups === [] || in_array('all', $allowedGroups, true);
        $groups = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';
        foreach ($report['groups'] as $head) {
            $sections = [];
            foreach ($head['sections'] ?? [] as $section) {
                $rows = [];
                foreach ($section['account_types'] ?? [] as $accountType) {
                    foreach ($accountType['rows'] ?? [] as $row) {
                        if (! empty($data['account_type_ulids']) && ! in_array($row['account_type']['ulid'] ?? '', $types, true)) continue;
                        if (! $all && ! in_array($row['party_category'] ?? 'accounts', $allowedGroups, true)) continue;
                        if (bccomp($row['debit'], '0', 4) === 0 && bccomp($row['credit'], '0', 4) === 0) continue;
                        $rows[] = ['account' => $row['account'], 'debit' => $row['debit'], 'credit' => $row['credit']];
                    }
                }
                if ($rows === []) continue;
                $debit = '0.0000'; $credit = '0.0000';
                foreach ($rows as $row) {
                    $debit = bcadd($debit, $row['debit'], 4);
                    $credit = bcadd($credit, $row['credit'], 4);
                }
                $sections[] = ['name' => $section['account_type_head']['name'] ?? 'Other',
                    'rows' => $rows, 'debit' => $debit, 'credit' => $credit];
            }
            if ($sections === []) continue;
            $debit = '0.0000'; $credit = '0.0000';
            foreach ($sections as $section) {
                $debit = bcadd($debit, $section['debit'], 4);
                $credit = bcadd($credit, $section['credit'], 4);
            }
            $groups[] = ['name' => $head['main_head']['name'] ?? 'Unclassified',
                'sections' => $sections, 'debit' => $debit, 'credit' => $credit];
            $totalDebit = bcadd($totalDebit, $debit, 4);
            $totalCredit = bcadd($totalCredit, $credit, 4);
        }
        $difference = bcsub($totalDebit, $totalCredit, 4);
        return response()->json([
            'as_of_date' => $data['as_of_date'],
            'summarized' => (bool) ($data['summarized'] ?? true),
            'groups' => $groups,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit,
                'difference' => ltrim($difference, '-') , 'difference_side' => bccomp($difference, '0', 4) >= 0 ? 'Dr' : 'Cr'],
            'limitations' => ['Ending Stock FIFO revaluation and Income/Loss adjustments are not inferred or injected. This report reflects posted ledger balances only.'],
        ]);
    }
}
