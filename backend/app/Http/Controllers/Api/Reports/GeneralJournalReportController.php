<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralJournalReportController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    private function authorizeReport(): void
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view accounting reports.', 403);
        }
    }

    private function journals(TenantContext $context)
    {
        return JournalEntry::query()
            ->forTenant($context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('status', 'posted');
    }

    public function options(TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        return response()->json([
            'document_types' => $this->journals($context)
                ->whereNotNull('document_type')->distinct()->orderBy('document_type')
                ->pluck('document_type')->values(),
        ]);
    }

    public function preview(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeReport();
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'document_types' => ['sometimes', 'array', 'max:30'],
            'document_types.*' => ['required', 'string', 'max:100', 'distinct'],
            'narration' => ['sometimes', 'boolean'],
            'only_problematic' => ['sometimes', 'boolean'],
            'print_format' => ['sometimes', 'in:transaction_wise,plain,group_by_doc,single_line'],
            'transaction_from' => ['nullable', 'integer', 'min:1'],
            'transaction_to' => ['nullable', 'integer', 'min:1', 'gte:transaction_from'],
            'balance_mode' => ['sometimes', 'in:all,between,not_between'],
            'balance_from' => ['sometimes', 'numeric', 'min:0'],
            'balance_to' => ['sometimes', 'numeric', 'min:0', 'gte:balance_from'],
        ]);
        $types = $data['document_types'] ?? [];
        $query = $this->journals($context)
            ->whereDate('entry_date', '>=', $data['from'])
            ->whereDate('entry_date', '<=', $data['to']);
        if ($types !== []) {
            $available = $this->journals($context)->whereIn('document_type', $types)
                ->distinct()->pluck('document_type')->all();
            if (count($available) !== count($types)) {
                throw new ApiException('DOCUMENT_TYPE_NOT_FOUND', 'Selected document type is unavailable.', 422);
            }
            $query->whereIn('document_type', $types);
        }
        $query->with(['lines' => function ($q) use ($context) {
            $q->where('tenant_id', $context->tenantId())
                ->with(['account:id,ulid,code,name'])
                ->orderBy('sort_order')->orderBy('id');
        }])->orderBy('entry_date')->orderBy('id');

        $limit = 300;
        $entries = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';
        $more = false;
        $format = $data['print_format'] ?? 'transaction_wise';
        $includeNarration = $data['narration'] ?? true;
        foreach ($query->cursor() as $journal) {
            // cursor() does not eager load relationships; fetch individually after scoped query.
            $journal->load(['lines' => fn ($q) => $q
                ->where('tenant_id', $context->tenantId())
                ->with('account:id,ulid,code,name')->orderBy('sort_order')->orderBy('id')]);
            if (isset($data['transaction_from']) || isset($data['transaction_to'])) {
                if (! ctype_digit((string) $journal->voucher_number)) continue;
                $number = (int) $journal->voucher_number;
                if ($number < ($data['transaction_from'] ?? 1) || $number > ($data['transaction_to'] ?? PHP_INT_MAX)) continue;
            }
            $debit = '0.0000';
            $credit = '0.0000';
            $lines = [];
            foreach ($journal->lines as $line) {
                $debit = bcadd($debit, (string) $line->debit, 4);
                $credit = bcadd($credit, (string) $line->credit, 4);
                $lines[] = [
                    'ulid' => $line->ulid,
                    'account' => [
                        'ulid' => $line->account?->ulid,
                        'code' => $line->account?->code,
                        'name' => $line->account?->name ?? 'Unavailable account',
                    ],
                    'narration' => $includeNarration ? ($line->description ?? '') : '',
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                ];
            }
            $diff = bcsub($debit, $credit, 4);
            $absolute = bccomp($diff, '0', 4) < 0 ? bcmul($diff, '-1', 4) : $diff;
            if (($data['only_problematic'] ?? false) && bccomp($absolute, '0', 4) === 0) continue;
            $mode = $data['balance_mode'] ?? 'all';
            if ($mode !== 'all') {
                $lower = (string) ($data['balance_from'] ?? '0');
                $upper = (string) ($data['balance_to'] ?? '0');
                $between = bccomp($absolute, $lower, 4) >= 0 && bccomp($absolute, $upper, 4) <= 0;
                if (($mode === 'between' && ! $between) || ($mode === 'not_between' && $between)) continue;
            }
            if (count($entries) >= $limit) { $more = true; break; }
            $entries[] = [
                'ulid' => $journal->ulid,
                'voucher_number' => $journal->voucher_number,
                'document_type' => $journal->document_type,
                'entry_date' => $journal->entry_date->format('Y-m-d'),
                'narration' => $includeNarration ? ($journal->description ?? '') : '',
                'lines' => $lines,
                'totals' => ['debit' => $debit, 'credit' => $credit, 'difference' => $diff],
            ];
            $totalDebit = bcadd($totalDebit, $debit, 4);
            $totalCredit = bcadd($totalCredit, $credit, 4);
        }
        return response()->json([
            'from' => $data['from'], 'to' => $data['to'], 'print_format' => $format,
            'entries' => $entries, 'has_more' => $more, 'limit' => $limit,
            'totals' => [
                'debit' => $totalDebit, 'credit' => $totalCredit,
                'difference' => bcsub($totalDebit, $totalCredit, 4),
            ],
            'note' => $more ? 'First 300 matching vouchers shown. Narrow filters to see the remaining vouchers.' : null,
        ]);
    }
}
