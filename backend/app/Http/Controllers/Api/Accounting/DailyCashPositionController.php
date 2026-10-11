<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Accounting\CashBookReportBuilder;
use App\Accounting\CashCreditSummaryBuilder;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DailyCashPositionController extends Controller
{
    public function __construct(
        private readonly CashBookReportBuilder $cashBook,
        private readonly CashCreditSummaryBuilder $cashCreditSummary,
        private readonly PermissionService $permissions,
    ) {}

    public function accounts(TenantContext $tenantContext): array
    {
        $this->requirePermission();

        return $this->cashBook
            ->cashAccounts($tenantContext->tenantId())
            ->map(fn (Account $account): array => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->accountType?->name,
                'is_cash' => (bool) ($account->accountType?->is_cash || $account->accountType?->code === '0010'),
                'is_bank' => (bool) ($account->accountType?->is_bank || $account->accountType?->code === '0012'),
            ])
            ->values()
            ->all();
    }

    public function cashBook(
        Request $request,
        string $accountUlid,
        TenantContext $tenantContext,
    ): JsonResponse {
        $this->requirePermission();

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'ignore_date' => ['nullable', 'boolean'],
        ]);

        $account = Account::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $accountUlid)
            ->where('is_active', true)
            ->whereHas('accountType', fn ($type) => $type
                ->where('is_cash', true)
                ->orWhere('is_bank', true)
                ->orWhereIn('code', ['0010', '0012']))
            ->with('accountType')
            ->first();

        if (! $account) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $ignoreDate = (bool) ($data['ignore_date'] ?? false);
        $dateFrom = $ignoreDate
            ? null
            : (string) ($data['date_from'] ?? now()->toDateString());
        $dateTo = $ignoreDate
            ? null
            : (string) ($data['date_to'] ?? $dateFrom);

        return response()->json($this->cashBook->build(
            $account,
            $tenantContext->tenantId(),
            $tenantContext->branchId(),
            $dateFrom,
            $dateTo,
            $ignoreDate,
        ));
    }

    public function summary(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $this->requirePermission();

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'filter_by' => ['nullable', 'string', Rule::in(['document', 'entry_date', 'user_name', 'sales_person'])],
            'only_for' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->cashCreditSummary->build(
            $tenantContext->tenantId(),
            $tenantContext->branchId(),
            (string) ($data['date'] ?? now()->toDateString()),
            isset($data['filter_by']) ? (string) $data['filter_by'] : null,
            isset($data['only_for']) ? (string) $data['only_for'] : null,
        ));
    }

    private function requirePermission(): void
    {
        abort_unless($this->permissions->can('accounting.journal.view'), 403);
    }
}
