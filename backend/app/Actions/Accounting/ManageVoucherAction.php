<?php

namespace App\Actions\Accounting;

use App\Accounting\OpeningBalancePoster;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Tenant;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageVoucherAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, string $idempotencyKey): JournalEntry
    {
        $tenantId = $this->tenantContext->tenantId();
        $branchId = $this->tenantContext->branchId();
        $documentType = $this->documentType((string) ($data['type'] ?? ''));
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to create a voucher.',
                422,
            );
        }

        return DB::transaction(function () use (
            $data,
            $tenantId,
            $branchId,
            $documentType,
            $idempotencyKey,
        ): JournalEntry {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $existing = JournalEntry::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->with(['lines.account.accountType'])
                ->first();

            if ($existing) {
                if (
                    $existing->document_type !== $documentType
                    || (int) $existing->branch_id !== $branchId
                    || ! $this->replayMatches($existing, $data)
                ) {
                    throw new ApiException(
                        'IDEMPOTENCY_KEY_CONFLICT',
                        'This Idempotency-Key was already used for a different voucher request.',
                        409,
                    );
                }

                return $existing;
            }

            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'document_type' => $documentType,
                'document_id' => null,
                'voucher_number' => null,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => $data['entry_date'],
                'description' => $this->nullableText($data['description'] ?? null),
                'status' => JournalStatus::Draft,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $entry->voucher_number = $this->voucherNumber($documentType, (int) $entry->id);
            $entry->save();

            $this->replaceLines($entry, $data);

            $this->audit->record('VOUCHER_DRAFT_CREATED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $entry->ulid,
                'voucher_number' => $entry->voucher_number,
                'document_type' => $entry->document_type,
            ]);

            return $entry->load(['lines.account.accountType']);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(JournalEntry $entry, array $data): JournalEntry
    {
        return DB::transaction(function () use ($entry, $data): JournalEntry {
            $entry = $this->lockOwnedVoucher($entry);

            if ($entry->isPosted()) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted vouchers are immutable.', 422);
            }

            if (array_key_exists('entry_date', $data)) {
                $entry->entry_date = $data['entry_date'];
            }
            if (array_key_exists('description', $data)) {
                $entry->description = $this->nullableText($data['description']);
            }
            $entry->save();

            $this->replaceLines($entry, $data);

            $this->audit->record('VOUCHER_DRAFT_UPDATED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $entry->ulid,
                'voucher_number' => $entry->voucher_number,
            ]);

            return $entry->load(['lines.account.accountType']);
        });
    }

    public function post(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry): JournalEntry {
            $entry = $this->lockOwnedVoucher($entry, withLines: true);

            if ($entry->isPosted()) {
                return $entry;
            }

            if ($entry->lines->count() < 2) {
                throw new ApiException(
                    'VOUCHER_LINES_REQUIRED',
                    'At least two voucher lines are required before posting.',
                    422,
                );
            }

            $this->poster->assertBalanced($entry);

            $entry->status = JournalStatus::Posted;
            $entry->posted_by = $this->tenantContext->userId();
            $entry->posted_at = now();
            $entry->save();

            $this->audit->record('VOUCHER_POSTED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $entry->ulid,
                'voucher_number' => $entry->voucher_number,
                'document_type' => $entry->document_type,
            ]);

            return $entry->refresh()->load(['lines.account.accountType']);
        });
    }

    public function delete(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry = $this->lockOwnedVoucher($entry);

            if ($entry->isPosted()) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted vouchers are immutable.', 422);
            }

            $ulid = $entry->ulid;
            $voucherNumber = $entry->voucher_number;
            $entry->lines()->delete();
            $entry->delete();

            $this->audit->record('VOUCHER_DRAFT_DELETED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $ulid,
                'voucher_number' => $voucherNumber,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function replaceLines(JournalEntry $entry, array $data): void
    {
        $entry->lines()->delete();

        if ($entry->document_type === JournalEntry::DOCUMENT_JOURNAL_VOUCHER) {
            $this->writeJournalLines($entry, $data['lines'] ?? []);

            return;
        }

        $headerAccount = $this->resolveAccount(
            (string) ($data['header_account_ulid'] ?? ''),
            'header_account_ulid',
        );

        $headerAccount->loadMissing('accountType');
        $isCashOrBank = in_array($headerAccount->accountType?->code, ['0010', '0012'], true)
            || $headerAccount->accountType?->is_cash
            || $headerAccount->accountType?->is_bank;

        if (! $isCashOrBank) {
            throw ValidationException::withMessages([
                'header_account_ulid' => 'Payment and receiving vouchers require an active cash or bank account.',
            ]);
        }

        $rows = $data['lines'] ?? [];
        if (! is_array($rows) || count($rows) < 1) {
            throw ValidationException::withMessages([
                'lines' => 'At least one voucher detail line is required.',
            ]);
        }

        $total = '0.0000';
        $resolved = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "lines.$index" => 'Invalid voucher line.',
                ]);
            }

            $account = $this->resolveAccount(
                (string) ($row['account_ulid'] ?? ''),
                "lines.$index.account_ulid",
            );

            if ((int) $account->id === (int) $headerAccount->id) {
                throw ValidationException::withMessages([
                    "lines.$index.account_ulid" => 'Voucher detail account must differ from the cash or bank account.',
                ]);
            }

            $amount = $this->positiveMoney(
                (string) ($row['amount'] ?? '0'),
                "lines.$index.amount",
            );
            $total = bcadd($total, $amount, 4);

            $resolved[] = [
                'account' => $account,
                'amount' => $amount,
                'description' => $this->nullableText($row['narration'] ?? null),
            ];
        }

        $payment = $entry->document_type === JournalEntry::DOCUMENT_PAYMENT_VOUCHER;
        $this->createLine(
            $entry,
            $headerAccount,
            $payment ? '0.0000' : $total,
            $payment ? $total : '0.0000',
            $entry->description,
            0,
            includeParty: false,
        );

        foreach ($resolved as $offset => $row) {
            $this->createLine(
                $entry,
                $row['account'],
                $payment ? $row['amount'] : '0.0000',
                $payment ? '0.0000' : $row['amount'],
                $row['description'],
                $offset + 1,
            );
        }
    }

    /**
     * @param mixed $rows
     */
    private function writeJournalLines(JournalEntry $entry, mixed $rows): void
    {
        if (! is_array($rows) || count($rows) < 2) {
            throw ValidationException::withMessages([
                'lines' => 'Journal vouchers require at least two lines.',
            ]);
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "lines.$index" => 'Invalid voucher line.',
                ]);
            }

            $account = $this->resolveAccount(
                (string) ($row['account_ulid'] ?? ''),
                "lines.$index.account_ulid",
            );
            $debit = $this->money((string) ($row['debit'] ?? '0'), "lines.$index.debit");
            $credit = $this->money((string) ($row['credit'] ?? '0'), "lines.$index.credit");

            if (
                (bccomp($debit, '0.0000', 4) <= 0 && bccomp($credit, '0.0000', 4) <= 0)
                || (bccomp($debit, '0.0000', 4) > 0 && bccomp($credit, '0.0000', 4) > 0)
            ) {
                throw ValidationException::withMessages([
                    "lines.$index" => 'Each journal line must contain a positive debit or a positive credit, but not both.',
                ]);
            }

            $this->createLine(
                $entry,
                $account,
                $debit,
                $credit,
                $this->nullableText($row['narration'] ?? null),
                $index,
            );
        }
    }

    private function createLine(
        JournalEntry $entry,
        Account $account,
        string $debit,
        string $credit,
        ?string $description,
        int $sortOrder,
        bool $includeParty = true,
    ): JournalLine {
        return JournalLine::query()->create([
            'tenant_id' => $this->tenantContext->tenantId(),
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
            'customer_id' => $includeParty ? $account->customer_id : null,
            'supplier_id' => $includeParty ? $account->supplier_id : null,
            'sort_order' => $sortOrder,
        ]);
    }

    private function resolveAccount(string $ulid, string $field): Account
    {
        $account = Account::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                $field => 'The selected account is missing, inactive, or belongs to another tenant.',
            ]);
        }

        return $account;
    }

    private function lockOwnedVoucher(JournalEntry $entry, bool $withLines = false): JournalEntry
    {
        $query = JournalEntry::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('branch_id', $this->tenantContext->branchId())
            ->whereKey($entry->id)
            ->whereIn('document_type', self::manualDocumentTypes())
            ->lockForUpdate();

        if ($withLines) {
            $query->with(['lines' => fn ($line) => $line->orderBy('sort_order'), 'lines.account.accountType']);
        }

        $locked = $query->first();
        if (! $locked) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $locked;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function replayMatches(JournalEntry $entry, array $data): bool
    {
        if (
            $entry->entry_date?->toDateString() !== (string) ($data['entry_date'] ?? '')
            || $entry->description !== $this->nullableText($data['description'] ?? null)
        ) {
            return false;
        }

        $lines = $entry->lines->sortBy('sort_order')->values();
        $rows = $data['lines'] ?? [];
        if (! is_array($rows)) {
            return false;
        }

        if ($entry->document_type === JournalEntry::DOCUMENT_JOURNAL_VOUCHER) {
            if ($lines->count() !== count($rows)) {
                return false;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    return false;
                }

                $line = $lines->get($index);
                if (
                    ! $line
                    || $line->account?->ulid !== (string) ($row['account_ulid'] ?? '')
                    || bccomp((string) $line->debit, $this->money((string) ($row['debit'] ?? '0'), "lines.$index.debit"), 4) !== 0
                    || bccomp((string) $line->credit, $this->money((string) ($row['credit'] ?? '0'), "lines.$index.credit"), 4) !== 0
                    || $line->description !== $this->nullableText($row['narration'] ?? null)
                ) {
                    return false;
                }
            }

            return true;
        }

        if ($lines->count() !== count($rows) + 1) {
            return false;
        }

        $header = $lines->first();
        if ($header?->account?->ulid !== (string) ($data['header_account_ulid'] ?? '')) {
            return false;
        }

        $payment = $entry->document_type === JournalEntry::DOCUMENT_PAYMENT_VOUCHER;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                return false;
            }

            $line = $lines->get($index + 1);
            $amount = $this->positiveMoney((string) ($row['amount'] ?? '0'), "lines.$index.amount");
            if (
                ! $line
                || $line->account?->ulid !== (string) ($row['account_ulid'] ?? '')
                || bccomp((string) ($payment ? $line->debit : $line->credit), $amount, 4) !== 0
                || $line->description !== $this->nullableText($row['narration'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    private function documentType(string $type): string
    {
        return match ($type) {
            'payment' => JournalEntry::DOCUMENT_PAYMENT_VOUCHER,
            'receiving' => JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
            'journal' => JournalEntry::DOCUMENT_JOURNAL_VOUCHER,
            default => throw ValidationException::withMessages([
                'type' => 'Voucher type must be payment, receiving, or journal.',
            ]),
        };
    }

    /**
     * @return list<string>
     */
    public static function manualDocumentTypes(): array
    {
        return [
            JournalEntry::DOCUMENT_PAYMENT_VOUCHER,
            JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
            JournalEntry::DOCUMENT_JOURNAL_VOUCHER,
        ];
    }

    private function voucherNumber(string $documentType, int $id): string
    {
        $prefix = match ($documentType) {
            JournalEntry::DOCUMENT_PAYMENT_VOUCHER => 'PV',
            JournalEntry::DOCUMENT_RECEIVING_VOUCHER => 'RV',
            JournalEntry::DOCUMENT_JOURNAL_VOUCHER => 'JV',
            default => 'V',
        };

        return $prefix.'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function positiveMoney(string $value, string $field): string
    {
        $amount = $this->money($value, $field);
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages([
                $field => 'Amount must be greater than zero.',
            ]);
        }

        return $amount;
    }

    private function money(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'Amount must be a non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
