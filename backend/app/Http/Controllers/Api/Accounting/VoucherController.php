<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Accounting\AccountBalanceCalculator;
use App\Actions\Accounting\ManageVoucherAction;
use App\Authz\PermissionService;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VoucherController extends Controller
{
    public function __construct(
        private readonly ManageVoucherAction $vouchers,
        private readonly PermissionService $permissions,
        private readonly AccountBalanceCalculator $balances,
    ) {}

    public function index(Request $request, TenantContext $tenantContext): array
    {
        $this->requirePermission('accounting.journal.view');

        $perPage = min(max($request->integer('per_page', 40), 1), 100);
        $query = JournalEntry::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->whereIn('document_type', ManageVoucherAction::manualDocumentTypes())
            ->with(['lines' => fn ($line) => $line->orderBy('sort_order'), 'lines.account.accountType'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('document_type', $this->documentTypeFromQuery((string) $request->string('type')));
        }
        if ($request->filled('status')) {
            $status = (string) $request->string('status');
            if (! in_array($status, [JournalStatus::Draft->value, JournalStatus::Posted->value], true)) {
                throw new ApiException('VALIDATION_FAILED', 'status must be draft or posted.', 422);
            }
            $query->where('status', $status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('entry_date', '>=', (string) $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('entry_date', '<=', (string) $request->string('date_to'));
        }
        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $raw).'%';
            $query->where(function ($inner) use ($term): void {
                $inner
                    ->where('voucher_number', 'ilike', $term)
                    ->orWhere('description', 'ilike', $term)
                    ->orWhereHas('lines', fn ($line) => $line
                        ->where('description', 'ilike', $term))
                    ->orWhereHas('lines.account', fn ($account) => $account
                        ->where('code', 'ilike', $term)
                        ->orWhere('name', 'ilike', $term));
            });
        }

        $page = $query->paginate($perPage);

        return [
            'data' => $page->getCollection()->map(fn (JournalEntry $entry) => $this->payload($entry))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function show(string $voucherUlid, TenantContext $tenantContext): JsonResponse
    {
        $this->requirePermission('accounting.journal.view');

        $entry = $this->ownedVoucher($voucherUlid, $tenantContext);

        return response()->json($this->payload($entry));
    }

    public function accounts(Request $request, TenantContext $tenantContext): array
    {
        $this->requirePermission('accounting.journal.view');

        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'before_voucher_ulid' => ['nullable', 'string', 'size:26'],
        ]);
        $asOf = $validated['as_of'] ?? now()->toDateString();
        $beforeEntryId = null;

        if (! empty($validated['before_voucher_ulid'])) {
            $beforeEntryId = JournalEntry::query()
                ->forTenant($tenantContext->tenantId())
                ->where('branch_id', $tenantContext->branchId())
                ->where('ulid', $validated['before_voucher_ulid'])
                ->whereIn('document_type', ManageVoucherAction::manualDocumentTypes())
                ->value('id');
        }

        $balanceScope = function ($entry) use ($asOf, $beforeEntryId): void {
            $entry->where('journal_entries.status', JournalStatus::Posted->value);

            if ($beforeEntryId) {
                $entry->where(function ($dated) use ($asOf, $beforeEntryId): void {
                    $dated
                        ->whereDate('journal_entries.entry_date', '<', $asOf)
                        ->orWhere(function ($sameDay) use ($asOf, $beforeEntryId): void {
                            $sameDay
                                ->whereDate('journal_entries.entry_date', '=', $asOf)
                                ->where('journal_entries.id', '<', $beforeEntryId);
                        });
                });

                return;
            }

            $entry->whereDate('journal_entries.entry_date', '<=', $asOf);
        };

        $query = Account::query()
            ->forTenant($tenantContext->tenantId())
            ->where('is_active', true)
            ->with('accountType')
            ->addSelect([
                'posted_debit' => JournalLine::query()
                    ->selectRaw('COALESCE(SUM(journal_lines.debit), 0)')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereColumn('journal_lines.account_id', 'accounts.id')
                    ->whereColumn('journal_lines.tenant_id', 'accounts.tenant_id')
                    ->where($balanceScope),
                'posted_credit' => JournalLine::query()
                    ->selectRaw('COALESCE(SUM(journal_lines.credit), 0)')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereColumn('journal_lines.account_id', 'accounts.id')
                    ->whereColumn('journal_lines.tenant_id', 'accounts.tenant_id')
                    ->where($balanceScope),
            ])
            ->orderBy('code')
            ->orderBy('name');

        if ($request->boolean('cash_only')) {
            $query->whereHas('accountType', fn ($type) => $type
                ->whereIn('code', ['0010', '0012'])
                ->orWhere('is_cash', true)
                ->orWhere('is_bank', true));
        }

        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $raw).'%';
            $query->where(fn ($account) => $account
                ->where('code', 'ilike', $term)
                ->orWhere('name', 'ilike', $term));
        }

        return $query->limit(250)->get()->map(function (Account $account): array {
            $debit = $this->balances->money((string) ($account->getAttribute('posted_debit') ?? '0'));
            $credit = $this->balances->money((string) ($account->getAttribute('posted_credit') ?? '0'));
            $balance = $this->balances->signedEffect($account, $debit, $credit);

            return [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'is_cash' => $account->accountType?->is_cash || $account->accountType?->code === '0010',
                'is_bank' => $account->accountType?->is_bank || $account->accountType?->code === '0012',
                'is_payable' => (bool) $account->accountType?->is_payable,
                'party_type' => $account->supplier_id ? 'vendor' : ($account->customer_id ? 'customer' : 'account'),
                'balance' => $balance,
            ];
        })->values()->all();
    }

    public function summary(Request $request, TenantContext $tenantContext): array
    {
        $this->requirePermission('accounting.journal.view');

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'type' => ['nullable', 'in:payment,receiving,journal'],
        ]);

        $dateFrom = $data['date_from'] ?? now()->toDateString();
        $dateTo = $data['date_to'] ?? $dateFrom;

        $query = JournalEntry::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->whereIn('document_type', ManageVoucherAction::manualDocumentTypes())
            ->where('status', JournalStatus::Posted->value)
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->with('lines')
            ->orderBy('entry_date')
            ->orderBy('id');

        if (! empty($data['type'])) {
            $query->where('document_type', $this->documentTypeFromQuery($data['type']));
        }

        $rows = [];
        foreach ($query->get() as $entry) {
            $date = $entry->entry_date->toDateString();
            $rows[$date] ??= ['date' => $date, 'debit' => '0.0000', 'credit' => '0.0000'];

            foreach ($entry->lines as $line) {
                $rows[$date]['debit'] = bcadd($rows[$date]['debit'], (string) $line->debit, 4);
                $rows[$date]['credit'] = bcadd($rows[$date]['credit'], (string) $line->credit, 4);
            }
        }

        return ['data' => array_values($rows)];
    }

    public function store(Request $request, ManageVoucherAction $vouchers): JsonResponse
    {
        $this->requirePermission('vouchers.create');
        $data = $request->validate($this->voucherRules(withType: true));

        $entry = $vouchers->create(
            $data,
            (string) $request->header('Idempotency-Key', ''),
        );

        return response()->json($this->payload($entry), 201);
    }

    public function update(
        Request $request,
        string $voucherUlid,
        TenantContext $tenantContext,
        ManageVoucherAction $vouchers,
    ): JsonResponse {
        $this->requirePermission('vouchers.create');
        $entry = $this->ownedVoucher($voucherUlid, $tenantContext);
        $data = $request->validate($this->voucherRules(withType: false));

        return response()->json($this->payload($vouchers->update($entry, $data)));
    }

    public function destroy(
        string $voucherUlid,
        TenantContext $tenantContext,
        ManageVoucherAction $vouchers,
    ): JsonResponse {
        $this->requirePermission('vouchers.create');
        $entry = $this->ownedVoucher($voucherUlid, $tenantContext);
        $vouchers->delete($entry);

        return response()->json(['ok' => true, 'deleted' => true]);
    }

    public function post(
        string $voucherUlid,
        TenantContext $tenantContext,
        ManageVoucherAction $vouchers,
    ): JsonResponse {
        $this->requirePermission('vouchers.approve');
        $entry = $this->ownedVoucher($voucherUlid, $tenantContext);

        return response()->json($this->payload($vouchers->post($entry)));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function voucherRules(bool $withType): array
    {
        $rules = [
            'entry_date' => ['required', 'date'],
            'book_number' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'header_account_ulid' => ['nullable', 'string', 'size:26'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.account_ulid' => ['required', 'string', 'size:26'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'lines.*.debit' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'lines.*.credit' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
        ];

        if ($withType) {
            $rules['type'] = ['required', 'in:payment,receiving,journal'];
        }

        return $rules;
    }

    private function ownedVoucher(string $voucherUlid, TenantContext $tenantContext): JournalEntry
    {
        $entry = JournalEntry::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('ulid', $voucherUlid)
            ->whereIn('document_type', ManageVoucherAction::manualDocumentTypes())
            ->with(['lines' => fn ($line) => $line->orderBy('sort_order'), 'lines.account.accountType'])
            ->first();

        if (! $entry) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(JournalEntry $entry): array
    {
        $entry->loadMissing(['lines' => fn ($line) => $line->orderBy('sort_order'), 'lines.account.accountType']);

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($entry->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $type = match ($entry->document_type) {
            JournalEntry::DOCUMENT_PAYMENT_VOUCHER => 'payment',
            JournalEntry::DOCUMENT_RECEIVING_VOUCHER => 'receiving',
            JournalEntry::DOCUMENT_JOURNAL_VOUCHER => 'journal',
            default => null,
        };

        return [
            'ulid' => $entry->ulid,
            'voucher_number' => $entry->voucher_number,
            'book_number' => $entry->book_number,
            'type' => $type,
            'entry_date' => $entry->entry_date?->toDateString(),
            'description' => $entry->description,
            'status' => $entry->status->value,
            'posted_at' => $entry->posted_at?->toIso8601String(),
            'total_debit' => $debit,
            'total_credit' => $credit,
            'header_account' => $type !== 'journal' ? $this->lineAccount($entry->lines->first()) : null,
            'lines' => $entry->lines->map(fn (JournalLine $line, int $index) => [
                'ulid' => $line->ulid,
                'is_header' => $type !== 'journal' && $index === 0,
                'account' => $this->lineAccount($line),
                'narration' => $line->description,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'party_type' => $line->supplier_id ? 'vendor' : ($line->customer_id ? 'customer' : 'account'),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lineAccount(?JournalLine $line): ?array
    {
        if (! $line?->account) {
            return null;
        }

        return [
            'ulid' => $line->account->ulid,
            'code' => $line->account->code,
            'name' => $line->account->name,
        ];
    }

    private function documentTypeFromQuery(string $type): string
    {
        return match ($type) {
            'payment' => JournalEntry::DOCUMENT_PAYMENT_VOUCHER,
            'receiving' => JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
            'journal' => JournalEntry::DOCUMENT_JOURNAL_VOUCHER,
            default => throw new ApiException('VALIDATION_FAILED', 'type must be payment, receiving, or journal.', 422),
        };
    }

    private function requirePermission(string $permission): void
    {
        abort_unless($this->permissions->can($permission), 403);
    }
}
