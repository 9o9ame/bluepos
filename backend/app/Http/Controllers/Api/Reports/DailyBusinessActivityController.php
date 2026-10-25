<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountType;
use App\Enums\JournalStatus;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyBusinessActivityController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    private function authorizeReport(): void
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }
    }

    public function types(TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        return response()->json(['account_types' => AccountType::query()
            ->forTenant($context->tenantId())->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['ulid', 'name'])->map(fn ($type) => [
                'ulid' => $type->ulid, 'name' => $type->name,
            ])->values()]);
    }

    public function report(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'account_type_ulids' => ['required', 'array', 'min:1', 'max:100'],
            'account_type_ulids.*' => ['required', 'string', 'ulid', 'distinct'],
        ]);

        $types = AccountType::query()->forTenant($context->tenantId())
            ->where('is_active', true)->whereIn('ulid', $data['account_type_ulids'])
            ->get(['id', 'ulid']);
        if ($types->count() !== count($data['account_type_ulids'])) {
            throw new ApiException('ACCOUNT_TYPE_NOT_FOUND', 'Selected account type is unavailable.', 404);
        }

        $accounts = Account::query()->forTenant($context->tenantId())
            ->where('is_active', true)->whereIn('account_type_id', $types->pluck('id'))
            ->with('accountType')->orderBy('name')->get();
        $accountIds = $accounts->pluck('id')->all();
        if ($accountIds === []) {
            return response()->json(['sections' => [], 'start_date' => $data['start_date'],
                'end_date' => $data['end_date']]);
        }

        $lines = DB::table('journal_lines')
            ->join('journal_entries', function ($join) use ($context) {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_entries.tenant_id', '=', $context->tenantId())
                    ->where('journal_entries.branch_id', '=', $context->branchId())
                    ->where('journal_entries.status', '=', JournalStatus::Posted->value);
            })
            ->where('journal_lines.tenant_id', $context->tenantId())
            ->whereIn('journal_lines.account_id', $accountIds)
            ->whereDate('journal_entries.entry_date', '<=', $data['end_date'])
            ->select('journal_lines.account_id', 'journal_lines.ulid as line_ulid',
                'journal_lines.debit', 'journal_lines.credit',
                'journal_lines.description as narration', 'journal_lines.sort_order',
                'journal_entries.entry_date', 'journal_entries.voucher_number',
                'journal_entries.document_type', 'journal_entries.description as entry_description')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.sort_order')->orderBy('journal_lines.ulid')
            ->get()->groupBy('account_id');

        $sections = $accounts->map(function ($account) use ($lines, $data) {
            $opening = '0.0000';
            $debitTotal = '0.0000';
            $creditTotal = '0.0000';
            $balance = '0.0000';
            $rows = [];
            $creditNormal = $account->accountType?->normal_balance === 'credit';
            foreach ($lines->get($account->id, collect()) as $line) {
                $debit = (string) $line->debit;
                $credit = (string) $line->credit;
                $effect = $creditNormal ? bcsub($credit, $debit, 4) : bcsub($debit, $credit, 4);
                $balance = bcadd($balance, $effect, 4);
                $date = substr((string) $line->entry_date, 0, 10);
                if ($date < $data['start_date']) {
                    $opening = $balance;
                    continue;
                }
                $debitTotal = bcadd($debitTotal, $debit, 4);
                $creditTotal = bcadd($creditTotal, $credit, 4);
                $rows[] = [
                    'line_ulid' => $line->line_ulid,
                    'date' => $date,
                    'trans_no' => $line->voucher_number ?: '-',
                    'doc' => strtoupper(str_replace('_', ' ', (string) $line->document_type)),
                    'narration' => $line->narration ?: $line->entry_description ?: '',
                    'debit' => $debit, 'credit' => $credit,
                    'balance' => $balance,
                ];
            }
            return [
                'account' => ['ulid' => $account->ulid, 'name' => $account->name,
                    'address' => $account->address, 'area' => $account->area,
                    'account_type' => $account->accountType?->name],
                'opening' => $opening, 'debit' => $debitTotal,
                'credit' => $creditTotal, 'closing' => $balance, 'rows' => $rows,
            ];
        })->filter(fn ($section) => $section['rows'] !== [])->values();

        return response()->json([
            'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
            'sections' => $sections,
        ]);
    }
}
