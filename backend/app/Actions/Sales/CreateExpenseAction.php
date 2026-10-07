<?php

namespace App\Actions\Sales;

use App\Accounting\OpeningBalancePoster;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateExpenseAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(array $data, string $idempotencyKey): Expense
    {
        $tenantId = $this->tenantContext->tenantId();
        $branchId = $this->tenantContext->branchId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to create an expense.',
                422,
            );
        }

        $amount = $this->normalizeMoney((string) ($data['amount'] ?? '0'));

        return DB::transaction(function () use ($data, $idempotencyKey, $tenantId, $branchId, $amount): Expense {
            $existing = Expense::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load(['expenseAccount', 'paymentAccount']);
            }

            $expenseAccount = $this->resolveAccount((string) $data['expense_account_ulid'], 'expense_account_ulid');
            $paymentAccount = $this->resolveAccount((string) $data['payment_account_ulid'], 'payment_account_ulid');

            if ($expenseAccount->id === $paymentAccount->id) {
                throw ValidationException::withMessages([
                    'payment_account_ulid' => 'Expense and payment accounts must be different.',
                ]);
            }

            $expense = Expense::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'expense_account_id' => $expenseAccount->id,
                'payment_account_id' => $paymentAccount->id,
                'expense_date' => $data['expense_date'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'journal_entry_ulid' => '',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $journal = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'document_type' => 'expense',
                'document_id' => $expense->id,
                'entry_date' => $expense->expense_date->toDateString(),
                'description' => $expense->description ?: 'Expense '.$expense->ulid,
                'status' => JournalStatus::Posted,
                'created_by' => $this->tenantContext->userId(),
                'posted_by' => $this->tenantContext->userId(),
                'posted_at' => now(),
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $expenseAccount->id,
                'description' => 'Expense',
                'debit' => $amount,
                'credit' => '0.0000',
                'sort_order' => 0,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $paymentAccount->id,
                'description' => 'Expense payment',
                'debit' => '0.0000',
                'credit' => $amount,
                'sort_order' => 1,
            ]);

            $this->poster->assertBalanced($journal->fresh(['lines']) ?? $journal);

            $expense->journal_entry_ulid = $journal->ulid;
            $expense->save();

            $this->audit->record('EXPENSE_POSTED', [
                'resource_type' => 'expense',
                'resource_ulid' => $expense->ulid,
                'amount' => $amount,
            ]);

            return $expense->load(['expenseAccount', 'paymentAccount']);
        });
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

    private function normalizeMoney(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a valid positive decimal with up to 4 places.',
            ]);
        }

        $amount = bcadd($value, '0', 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Expense amount must be greater than zero.',
            ]);
        }

        return $amount;
    }
}
