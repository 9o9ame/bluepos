<?php

namespace App\Http\Controllers\Api\Reports;

use App\Accounting\TrialBalanceReportBuilder;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrialBalanceController extends Controller
{
    public function __construct(
        private readonly TrialBalanceReportBuilder $builder,
        private readonly PermissionService $permissions,
    ) {}

    public function __invoke(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }

        $data = $request->validate([
            'as_of_date' => ['required_unless:mode,six_columns', 'date_format:Y-m-d'],
            'mode' => ['nullable', Rule::in(['six_columns'])],
            'main_heads' => ['sometimes', 'array', 'max:200'],
            'main_heads.*' => ['required', 'string', 'ulid', 'distinct'],
            'sub_heads' => ['sometimes', 'array', 'max:200'],
            'sub_heads.*' => ['required', 'string', 'ulid', 'distinct'],
            'account_types' => ['sometimes', 'array', 'max:500'],
            'account_types.*' => ['required', 'string', 'ulid', 'distinct'],
            'start_date' => ['required_if:mode,six_columns', 'date_format:Y-m-d'],
            'end_date' => ['required_if:mode,six_columns', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'group_by' => ['nullable', Rule::in([
                'account_type',
                'account_type_head',
                'main_account',
                'level_wise',
            ])],
            'arrange_by' => ['nullable', Rule::in([
                'balance_asc',
                'balance_desc',
                'credit_asc',
                'credit_desc',
                'debit_asc',
                'debit_desc',
                'code_asc',
                'code_desc',
                'name_asc',
                'name_desc',
            ])],
        ]);

        if (($data['mode'] ?? null) === 'six_columns') {
            return response()->json($this->builder->buildSixColumns(
                $tenantContext->tenantId(),
                $tenantContext->branchId(),
                (string) $data['start_date'],
                (string) $data['end_date'],
                (string) ($data['group_by'] ?? 'level_wise'),
                (string) ($data['arrange_by'] ?? 'code_asc'),
                $data['main_heads'] ?? [],
                $data['sub_heads'] ?? [],
                $data['account_types'] ?? [],
            ));
        }

        return response()->json($this->builder->build(
            $tenantContext->tenantId(),
            $tenantContext->branchId(),
            (string) $data['as_of_date'],
            (string) ($data['group_by'] ?? 'level_wise'),
            (string) ($data['arrange_by'] ?? 'code_asc'),
        ));
    }
}
