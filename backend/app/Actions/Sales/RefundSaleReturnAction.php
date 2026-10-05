<?php

namespace App\Actions\Sales;

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
use App\Models\SaleReturnRefund;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundSaleReturnAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
        private readonly PartyLeafAccountSync $partyAccounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(
        SaleReturn $document,
        array $data,
        string $idempotencyKey,
    ): SaleReturnRefund {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to refund a sales return.',
                422,
            );
        }

        $amount = $this->normalizeMoney((string) ($data['amount'] ?? '0'));

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Refund amount must be greater than zero.',
            ]);
        }

        $method = SalePaymentMethod::from(
            (string) ($data['method'] ?? SalePaymentMethod::Cash->value)
        );

        return DB::transaction(function () use (
            $document,
            $data,
            $idempotencyKey,
            $tenantId,
            $amount,
            $method,
        ): SaleReturnRefund {
            $existing = SaleReturnRefund::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load('account');
            }

            $document = SaleReturn::query()
                ->forTenant($tenantId)
                ->whereKey($document->id)
                ->where('branch_id', $this->tenantContext->branchId())
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== SaleReturnStatus::Posted) {
                throw new ApiException(
                    'DOCUMENT_NOT_POSTED',
                    'Only posted sales returns can be refunded.',
                    422,
                );
            }

            $refundable = $this->refundableAmount($document);

            if (bccomp($amount, $refundable, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Refund cannot exceed the remaining refund balance of '.$refundable.'.',
                ]);
            }

            $collectedByMethod = bcadd(
                (string) SalePayment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('sale_id', $document->sale_id)
                    ->where('method', $method->value)
                    ->sum('amount'),
                '0',
                4,
            );

            $alreadyRefundedByMethod = bcadd(
                (string) SaleReturnRefund::query()
                    ->where('tenant_id', $tenantId)
                    ->where('method', $method->value)
                    ->whereHas('saleReturn', fn ($query) => $query->where('sale_id', $document->sale_id))
                    ->sum('amount'),
                '0',
                4,
            );

            $availableByMethod = bcsub($collectedByMethod, $alreadyRefundedByMethod, 4);
            if (bccomp($availableByMethod, '0', 4) < 0) {
                $availableByMethod = '0.0000';
            }

            if (bccomp($amount, $availableByMethod, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => ucfirst($method->value).' refund cannot exceed the unrefunded '
                        .$method->value.' payment amount of '.$availableByMethod.'.',
                ]);
            }

            [$debitAccount, $creditAccount] = $this->resolveAccounts($document, $method);

            $journal = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $document->branch_id,
                'document_type' => 'sale_return_refund',
                'document_id' => $document->id,
                'entry_date' => now()->toDateString(),
                'description' => 'Refund against sales return '.$document->document_number,
                'status' => JournalStatus::Posted,
                'created_by' => $this->tenantContext->userId(),
                'posted_by' => $this->tenantContext->userId(),
                'posted_at' => now(),
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $debitAccount->id,
                'description' => 'Sales return settlement',
                'debit' => $amount,
                'credit' => '0.0000',
                'sort_order' => 0,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $creditAccount->id,
                'description' => $method->value,
                'debit' => '0.0000',
                'credit' => $amount,
                'sort_order' => 1,
            ]);

            $this->poster->assertBalanced($journal->fresh(['lines']) ?? $journal);

            $refund = SaleReturnRefund::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $document->branch_id,
                'sale_return_id' => $document->id,
                'account_id' => $creditAccount->id,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'amount' => $amount,
                'journal_entry_ulid' => $journal->ulid,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('SALE_RETURN_REFUNDED', [
                'resource_type' => 'sale_return_refund',
                'resource_ulid' => $refund->ulid,
                'sale_return_ulid' => $document->ulid,
                'amount' => $amount,
                'method' => $method->value,
            ]);

            return $refund->load('account');
        });
    }

    public function refundableAmount(SaleReturn $document): string
    {
        $tenantId = (int) $document->tenant_id;

        $sale = Sale::query()
            ->forTenant($tenantId)
            ->whereKey($document->sale_id)
            ->first();

        if (! $sale) {
            return '0.0000';
        }

        $paid = bcadd(
            (string) SalePayment::query()
                ->where('tenant_id', $tenantId)
                ->where('sale_id', $sale->id)
                ->sum('amount'),
            '0',
            4,
        );

        $postedReturns = bcadd(
            (string) SaleReturn::query()
                ->where('tenant_id', $tenantId)
                ->where('sale_id', $sale->id)
                ->where('status', SaleReturnStatus::Posted->value)
                ->sum('grand_total'),
            '0',
            4,
        );

        $netSale = bcsub((string) $sale->grand_total, $postedReturns, 4);
        if (bccomp($netSale, '0', 4) < 0) {
            $netSale = '0.0000';
        }

        $allRefunded = bcadd(
            (string) SaleReturnRefund::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('saleReturn', fn ($query) => $query->where('sale_id', $sale->id))
                ->sum('amount'),
            '0',
            4,
        );

        $overpaymentRemaining = bcsub(bcsub($paid, $netSale, 4), $allRefunded, 4);
        if (bccomp($overpaymentRemaining, '0', 4) < 0) {
            $overpaymentRemaining = '0.0000';
        }

        $thisReturnRefunded = bcadd(
            (string) SaleReturnRefund::query()
                ->where('tenant_id', $tenantId)
                ->where('sale_return_id', $document->id)
                ->sum('amount'),
            '0',
            4,
        );

        $thisReturnRemaining = bcsub((string) $document->grand_total, $thisReturnRefunded, 4);
        if (bccomp($thisReturnRemaining, '0', 4) < 0) {
            $thisReturnRemaining = '0.0000';
        }

        return bccomp($thisReturnRemaining, $overpaymentRemaining, 4) <= 0
            ? $thisReturnRemaining
            : $overpaymentRemaining;
    }

    /**
     * @return array{0:Account,1:Account}
     */
    private function resolveAccounts(
        SaleReturn $document,
        SalePaymentMethod $method,
    ): array {
        $tenantId = $this->tenantContext->tenantId();

        $settings = BusinessSetting::query()
            ->forTenant($tenantId)
            ->first();

        $clearing = $this->clearingAccount($settings, $tenantId);

        if ($method === SalePaymentMethod::Credit) {
            $customerId = $document->customer_id === null
                ? null
                : (int) $document->customer_id;

            if ($customerId === null) {
                throw new ApiException(
                    'CREDIT_REQUIRES_CUSTOMER',
                    'An on-account refund needs the sales return to have a customer.',
                    422,
                );
            }

            $customer = Customer::query()
                ->forTenant($tenantId)
                ->whereKey($customerId)
                ->first();

            if (! $customer || ! $customer->is_active) {
                throw new ApiException(
                    'CREDIT_REQUIRES_CUSTOMER',
                    'This customer is missing or inactive.',
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

            return [$clearing, $receivable];
        }

        $accountId = $settings?->default_cash_account_id;

        if (! $accountId) {
            throw new ApiException(
                'CASH_ACCOUNT_REQUIRED',
                'Configure a default cash account in business settings before refunding a sales return.',
                422,
            );
        }

        $cash = Account::query()
            ->forTenant($tenantId)
            ->whereKey($accountId)
            ->first();

        if (! $cash || ! $cash->is_active) {
            throw new ApiException(
                'CASH_ACCOUNT_REQUIRED',
                'Configured cash account is missing or inactive.',
                422,
            );
        }

        return [$clearing, $cash];
    }

    private function clearingAccount(
        ?BusinessSetting $settings,
        int $tenantId,
    ): Account {
        $accountId = $settings?->sales_clearing_account_id;

        if (! $accountId) {
            throw new ApiException(
                'SALES_CLEARING_ACCOUNT_REQUIRED',
                'Configure a sales clearing account in business settings before refunding a sales return.',
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
