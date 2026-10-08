<?php

namespace App\Actions\Purchases;

use App\Accounting\OpeningBalancePoster;
use App\Accounting\PartyLeafAccountSync;
use App\Enums\JournalStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\SalePaymentMethod;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayPurchaseInvoiceAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
        private readonly PartyLeafAccountSync $partyAccounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(PurchaseInvoice $invoice, array $data, string $idempotencyKey): PurchasePayment
    {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header is required to record a purchase payment.',
                422,
            );
        }

        $amount = $this->normalizeMoney((string) ($data['amount'] ?? '0'));
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than zero.',
            ]);
        }

        $method = SalePaymentMethod::from((string) ($data['method'] ?? SalePaymentMethod::Cash->value));
        if ($method === SalePaymentMethod::Credit) {
            throw ValidationException::withMessages([
                'method' => 'Purchase payments support cash, card, or bank methods only.',
            ]);
        }

        return DB::transaction(function () use (
            $invoice,
            $data,
            $idempotencyKey,
            $tenantId,
            $amount,
            $method,
        ): PurchasePayment {
            $existing = PurchasePayment::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $invoice = PurchaseInvoice::query()
                ->forTenant($tenantId)
                ->whereKey($invoice->id)
                ->with('supplier.accountType')
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
                throw new ApiException(
                    'PURCHASE_NOT_POSTED',
                    'Purchase payments can only be recorded against posted purchase invoices.',
                    422,
                );
            }

            if (PurchaseReturn::query()
                ->forTenant($tenantId)
                ->where('purchase_invoice_id', $invoice->id)
                ->where('status', PurchaseReturnStatus::Posted->value)
                ->exists()) {
                throw new ApiException(
                    'PURCHASE_RETURN_ACCOUNTING_REQUIRED',
                    'Purchase payments are blocked while posted purchase returns do not yet have accounting journals.',
                    422,
                );
            }

            $outstanding = $this->outstandingAmount($invoice);
            if (bccomp($amount, $outstanding, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the outstanding balance of '.$outstanding.'.',
                ]);
            }

            $payable = $this->supplierPayableAccount($invoice, $tenantId);
            $cash = $this->cashAccount($tenantId);

            if ((int) $payable->id === (int) $cash->id) {
                throw new ApiException(
                    'CASH_ACCOUNT_REQUIRED',
                    'Default cash account cannot be the supplier payable account.',
                    422,
                );
            }

            $journal = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $invoice->branch_id,
                'document_type' => JournalEntry::DOCUMENT_PURCHASE_PAYMENT,
                'document_id' => $invoice->id,
                'entry_date' => now()->toDateString(),
                'description' => 'Payment against purchase '.$invoice->document_number,
                'status' => JournalStatus::Posted,
                'created_by' => $this->tenantContext->userId(),
                'posted_by' => $this->tenantContext->userId(),
                'posted_at' => now(),
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $payable->id,
                'supplier_id' => $invoice->supplier_id,
                'description' => 'Purchase settlement',
                'debit' => $amount,
                'credit' => '0.0000',
                'sort_order' => 0,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $cash->id,
                'description' => $method->value,
                'debit' => '0.0000',
                'credit' => $amount,
                'sort_order' => 1,
            ]);

            $this->poster->assertBalanced($journal->fresh(['lines']) ?? $journal);

            $payment = PurchasePayment::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $invoice->branch_id,
                'purchase_invoice_id' => $invoice->id,
                'account_id' => $cash->id,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'amount' => $amount,
                'journal_entry_ulid' => $journal->ulid,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $this->audit->record('PURCHASE_PAYMENT_RECORDED', [
                'resource_type' => 'purchase_payment',
                'resource_ulid' => $payment->ulid,
                'purchase_ulid' => $invoice->ulid,
                'amount' => $amount,
            ]);

            return $payment;
        });
    }

    public function outstandingAmount(PurchaseInvoice $invoice): string
    {
        $paid = (string) PurchasePayment::query()
            ->where('tenant_id', (int) $invoice->tenant_id)
            ->where('purchase_invoice_id', $invoice->id)
            ->sum('amount');

        $outstanding = bcsub((string) $invoice->grand_total, $paid, 4);

        return bccomp($outstanding, '0', 4) < 0 ? '0.0000' : $outstanding;
    }

    private function supplierPayableAccount(PurchaseInvoice $invoice, int $tenantId): Account
    {
        $supplier = $invoice->supplier;
        if (! $supplier || ! $supplier->is_active || ! $supplier->account_type_id || ! $supplier->accountType?->is_payable) {
            throw new ApiException(
                'SUPPLIER_PAYABLE_ACCOUNT_REQUIRED',
                'An active supplier payable account is required before recording payment.',
                422,
            );
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->where('supplier_id', $supplier->id)
            ->first();

        if (! $account) {
            $account = $this->partyAccounts->syncSupplier($supplier);
        }

        if (! $account->is_active) {
            throw new ApiException(
                'SUPPLIER_PAYABLE_ACCOUNT_REQUIRED',
                'The supplier payable account is inactive.',
                422,
            );
        }

        return $account;
    }

    private function cashAccount(int $tenantId): Account
    {
        $settings = BusinessSetting::query()
            ->forTenant($tenantId)
            ->first();

        $accountId = $settings?->default_cash_account_id;
        if (! $accountId) {
            throw new ApiException(
                'CASH_ACCOUNT_REQUIRED',
                'Configure a default cash account in business settings before recording purchase payments.',
                422,
            );
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->whereKey($accountId)
            ->first();

        if (! $account || ! $account->is_active) {
            throw new ApiException(
                'CASH_ACCOUNT_REQUIRED',
                'Configured cash account is missing or inactive.',
                422,
            );
        }

        return $account;
    }

    private function normalizeMoney(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a valid non-negative decimal with up to 4 places.',
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
