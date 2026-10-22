<?php

namespace App\Actions\Sales;

use App\Accounting\CashierTransactionLock;
use App\Accounting\OpeningBalancePoster;
use App\Accounting\PartyLeafAccountSync;
use App\Enums\JournalStatus;
use App\Enums\SalePaymentMethod;
use App\Enums\SaleReturnStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment against a posted sale and writes the matching journal entry.
 *
 * Partial payments are allowed: each call reduces the outstanding balance by its
 * own amount, and the amount may never exceed what is still due. The sale row is
 * locked for update so two concurrent payments cannot both see the old balance.
 *
 * The payment journal debits the receiving account (cash/bank, or the customer's
 * AR leaf for credit) and credits the configured sales clearing account. Sales
 * revenue and COGS are not posted here — that journal does not exist yet.
 */
class CollectSalePaymentAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
        private readonly PartyLeafAccountSync $partyAccounts,
        private readonly CashierTransactionLock $cashierLock,
        private readonly BuildSaleEffectiveSnapshotAction $snapshots,
        private readonly BuildSaleReturnEffectiveSnapshotAction $returnSnapshots,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(Sale $sale, array $data, string $idempotencyKey): SalePayment
    {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to collect a payment.',
                422,
            );
        }

        $amount = $this->normalizeMoney((string) ($data['amount'] ?? '0'));

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than zero.',
            ]);
        }

        $method = SalePaymentMethod::from(
            (string) ($data['method'] ?? SalePaymentMethod::Cash->value)
        );

        return DB::transaction(function () use (
            $sale,
            $data,
            $idempotencyKey,
            $tenantId,
            $amount,
            $method
        ): SalePayment {
            $existing = SalePayment::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $this->cashierLock->lockCurrent();

            $sale = Sale::query()
                ->forTenant($tenantId)
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            $outstanding = $this->outstandingAmount($sale);

            if (bccomp($amount, $outstanding, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the outstanding balance of '.$outstanding.'.',
                ]);
            }

            if ($method === SalePaymentMethod::Credit) {
                $receivable = $this->receivableAccount($sale);

                $payment = SalePayment::query()->create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $sale->branch_id,
                    'sale_id' => $sale->id,
                    'account_id' => $receivable->id,
                    'method' => $method,
                    'reference' => $data['reference'] ?? null,
                    'amount' => $amount,
                    'journal_entry_ulid' => null,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $this->tenantContext->userId(),
                ]);

                $this->audit->record('SALE_PAYMENT_COLLECTED', [
                    'resource_type' => 'sale_payment',
                    'resource_ulid' => $payment->ulid,
                    'sale_ulid' => $sale->ulid,
                    'amount' => $amount,
                    'method' => $method->value,
                ]);

                return $payment;
            }

            [$debitAccount, $creditAccount] = $this->resolveAccounts(
                $sale,
                $method,
                isset($data['account_ulid']) ? (string) $data['account_ulid'] : null,
            );

            $journal = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $sale->branch_id,
                'document_type' => 'sale_payment',
                'document_id' => $sale->id,
                'entry_date' => $sale->sale_date?->toDateString() ?? now()->toDateString(),
                'description' => 'Payment received against sale '.$sale->document_number,
                'status' => JournalStatus::Posted,
                'created_by' => $this->tenantContext->userId(),
                'posted_by' => $this->tenantContext->userId(),
                'posted_at' => now(),
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $debitAccount->id,
                'description' => $method->value,
                'debit' => $amount,
                'credit' => '0.0000',
                'sort_order' => 0,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $creditAccount->id,
                'description' => 'Sale settlement',
                'debit' => '0.0000',
                'credit' => $amount,
                'sort_order' => 1,
            ]);

            // Never post an unbalanced journal.
            $this->poster->assertBalanced($journal->fresh(['lines']) ?? $journal);

            $payment = SalePayment::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'account_id' => $debitAccount->id,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'amount' => $amount,
                'journal_entry_ulid' => $journal->ulid,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('SALE_PAYMENT_COLLECTED', [
                'resource_type' => 'sale_payment',
                'resource_ulid' => $payment->ulid,
                'sale_ulid' => $sale->ulid,
                'amount' => $amount,
            ]);

            return $payment;
        });
    }

    /**
     * Grand total minus everything already paid on this sale.
     */
    public function outstandingAmount(Sale $sale): string
    {
        $paid = (string) SalePayment::query()
            ->where('tenant_id', (int) $sale->tenant_id)
            ->where('sale_id', $sale->id)
            ->sum('amount');

        $returned = '0.0000';
        $returns = SaleReturn::query()
            ->where('tenant_id', (int) $sale->tenant_id)
            ->where('sale_id', $sale->id)
            ->where('status', SaleReturnStatus::Posted->value)
            ->with('latestRevision')
            ->get();

        foreach ($returns as $return) {
            $effectiveReturn = $this->returnSnapshots->current($return);
            $returned = bcadd(
                $returned,
                (string) ($effectiveReturn['grand_total'] ?? $return->grand_total),
                4,
            );
        }

        $effective = $this->snapshots->current($sale);
        $netSaleTotal = bcsub(
            (string) ($effective['grand_total'] ?? $sale->grand_total),
            $returned,
            4,
        );
        if (bccomp($netSaleTotal, '0', 4) < 0) {
            $netSaleTotal = '0.0000';
        }

        $outstanding = bcsub($netSaleTotal, $paid, 4);

        return bccomp($outstanding, '0', 4) < 0 ? '0.0000' : $outstanding;
    }

    /**
     * @return array{0: Account, 1: Account}
     */
    /**
     * @return array{0: Account, 1: Account}
     */
    private function resolveAccounts(
        Sale $sale,
        SalePaymentMethod $method,
        ?string $accountUlid,
    ): array {
        $tenantId = $this->tenantContext->tenantId();

        $settings = BusinessSetting::query()
            ->forTenant($tenantId)
            ->first();

        $receivingAccount = match ($method) {
            SalePaymentMethod::Cash => $this->cashAccount($settings, $tenantId),
            SalePaymentMethod::Card,
            SalePaymentMethod::Bank => $this->bankAccount($accountUlid, $tenantId),
            SalePaymentMethod::Credit => throw new ApiException(
                'INVALID_PAYMENT_METHOD',
                'Credit settlement is handled through the customer receivable account.',
                422,
            ),
        };

        return [
            $receivingAccount,
            $sale->customer_id !== null
                ? $this->receivableAccount($sale)
                : $this->clearingAccount($settings, $tenantId),
        ];
    }

    private function cashAccount(?BusinessSetting $settings, int $tenantId): Account
    {
        $accountId = $settings?->default_cash_account_id;

        if (! $accountId) {
            throw new ApiException(
                'CASH_ACCOUNT_REQUIRED',
                'Configure a default cash account in business settings before collecting payment.',
                422,
            );
        }

        $cash = Account::query()
            ->forTenant($tenantId)
            ->whereKey($accountId)
            ->where('is_active', true)
            ->whereHas('accountType', fn ($type) => $type
                ->where('is_cash', true)
                ->orWhere('code', '0010'))
            ->first();

        if (! $cash) {
            throw new ApiException(
                'CASH_ACCOUNT_REQUIRED',
                'Configured cash account is missing, inactive, or is not a cash account.',
                422,
            );
        }

        return $cash;
    }

    private function bankAccount(?string $accountUlid, int $tenantId): Account
    {
        $accountUlid = trim((string) $accountUlid);

        if ($accountUlid === '') {
            throw ValidationException::withMessages([
                'account_ulid' => 'Select the bank account receiving this payment.',
            ]);
        }

        $bank = Account::query()
            ->forTenant($tenantId)
            ->where('ulid', $accountUlid)
            ->where('is_active', true)
            ->whereHas('accountType', fn ($type) => $type
                ->where('is_bank', true)
                ->orWhere('code', '0012'))
            ->first();

        if (! $bank) {
            throw ValidationException::withMessages([
                'account_ulid' => 'The selected bank account is not available.',
            ]);
        }

        return $bank;
    }

    private function receivableAccount(Sale $sale): Account
    {
        $tenantId = $this->tenantContext->tenantId();
        $customerId = $sale->customer_id === null
            ? null
            : (int) $sale->customer_id;

        if ($customerId === null) {
            throw new ApiException(
                'CREDIT_REQUIRES_CUSTOMER',
                'An on-account settlement requires the sale to have a customer.',
                422,
            );
        }

        $customer = Customer::query()
            ->forTenant($tenantId)
            ->whereKey($customerId)
            ->with('accountType')
            ->first();

        if (! $customer || ! $customer->is_active || ! $customer->accountType?->is_receivable) {
            throw new ApiException(
                'CUSTOMER_RECEIVABLE_ACCOUNT_REQUIRED',
                'Configure the customer with an active Accounts Receivable account type.',
                422,
            );
        }

        $receivable = Account::query()
            ->forTenant($tenantId)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $receivable) {
            $receivable = $this->partyAccounts->syncCustomer($customer);
        }

        if (! $receivable->is_active) {
            throw new ApiException(
                'CUSTOMER_RECEIVABLE_ACCOUNT_REQUIRED',
                'The customer receivable account is inactive.',
                422,
            );
        }

        return $receivable;
    }

    private function clearingAccount(
        ?BusinessSetting $settings,
        int $tenantId
    ): Account {
        $accountId = $settings?->sales_clearing_account_id;

        if (! $accountId) {
            throw new ApiException(
                'SALES_CLEARING_ACCOUNT_REQUIRED',
                'Configure a sales clearing account in business settings before collecting payment.',
                422,
            );
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->whereKey($accountId)
            ->first();

        if (! $account || ! $account->is_active) {
            throw new ApiException(
                'SALES_CLEARING_ACCOUNT_REQUIRED',
                'Configured sales clearing account is missing or inactive.',
                422,
            );
        }

        return $account;
    }

    private function normalizeMoney(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}