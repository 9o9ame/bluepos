<?php

namespace App\Actions\Purchases;

use App\Accounting\OpeningBalancePoster;
use App\Accounting\PartyLeafAccountSync;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseReturn;
use App\Tenancy\TenantContext;

class PostPurchaseReturnAccountingAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PartyLeafAccountSync $partyAccounts,
        private readonly OpeningBalancePoster $poster,
    ) {}

    public function execute(PurchaseReturn $document): ?JournalEntry
    {
        $tenantId = $this->tenantContext->tenantId();

        if ((int) $document->tenant_id !== $tenantId) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $amount = bcadd((string) $document->grand_total, '0', 4);
        if (bccomp($amount, '0', 4) < 0) {
            throw new ApiException(
                'PURCHASE_RETURN_TOTAL_INVALID',
                'Purchase return total cannot be negative when posting accounting.',
                422,
            );
        }

        if (bccomp($amount, '0', 4) === 0) {
            return null;
        }

        $existing = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_RETURN)
            ->where('document_id', $document->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (! $existing->isPosted()) {
                throw new ApiException(
                    'PURCHASE_RETURN_ACCOUNTING_CONFLICT',
                    'An unfinished purchase return accounting journal already exists.',
                    409,
                );
            }

            $existing->load('lines');
            $this->poster->assertBalanced($existing);

            return $existing;
        }

        $invoice = $document->purchaseInvoice;
        if (! $invoice) {
            throw new ApiException(
                'PURCHASE_ACCOUNTING_REQUIRED',
                'The original purchase accounting journal is required before posting a purchase return.',
                422,
            );
        }

        $purchaseJournalExists = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_INVOICE)
            ->where('document_id', $invoice->id)
            ->where('status', JournalStatus::Posted)
            ->exists();

        if (! $purchaseJournalExists) {
            throw new ApiException(
                'PURCHASE_ACCOUNTING_REQUIRED',
                'The original purchase accounting journal is required before posting a purchase return.',
                422,
            );
        }

        $supplier = $document->supplier;
        if (! $supplier || ! $supplier->is_active) {
            throw new ApiException(
                'SUPPLIER_PAYABLE_ACCOUNT_REQUIRED',
                'An active supplier with a payable account is required before posting this purchase return.',
                422,
            );
        }

        $supplier->loadMissing('accountType');
        if (! $supplier->account_type_id || ! $supplier->accountType?->is_payable) {
            throw new ApiException(
                'SUPPLIER_PAYABLE_ACCOUNT_REQUIRED',
                'Configure the supplier with an Accounts Payable account type before posting this purchase return.',
                422,
            );
        }

        $payable = Account::query()
            ->forTenant($tenantId)
            ->where('supplier_id', $supplier->id)
            ->first();

        if (! $payable) {
            $payable = $this->partyAccounts->syncSupplier($supplier);
        }

        if (! $payable->is_active) {
            throw new ApiException(
                'SUPPLIER_PAYABLE_ACCOUNT_REQUIRED',
                'The supplier payable account is inactive.',
                422,
            );
        }

        $clearing = $this->purchaseClearingAccount($tenantId);
        if ((int) $clearing->id === (int) $payable->id) {
            throw new ApiException(
                'PURCHASE_CLEARING_ACCOUNT_REQUIRED',
                'Purchase clearing account cannot be the supplier payable account.',
                422,
            );
        }

        $journal = JournalEntry::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $document->branch_id,
            'document_type' => JournalEntry::DOCUMENT_PURCHASE_RETURN,
            'document_id' => $document->id,
            'entry_date' => $document->return_date?->toDateString() ?? now()->toDateString(),
            'description' => 'Purchase return '.$document->document_number,
            'status' => JournalStatus::Posted,
            'created_by' => $this->tenantContext->userId(),
            'posted_by' => $this->tenantContext->userId(),
            'posted_at' => now(),
        ]);

        JournalLine::query()->create([
            'tenant_id' => $tenantId,
            'journal_entry_id' => $journal->id,
            'account_id' => $payable->id,
            'supplier_id' => $supplier->id,
            'description' => 'Supplier payable reversal',
            'debit' => $amount,
            'credit' => '0.0000',
            'sort_order' => 0,
        ]);

        JournalLine::query()->create([
            'tenant_id' => $tenantId,
            'journal_entry_id' => $journal->id,
            'account_id' => $clearing->id,
            'description' => 'Purchase clearing reversal',
            'debit' => '0.0000',
            'credit' => $amount,
            'sort_order' => 1,
        ]);

        $journal->load('lines');
        $this->poster->assertBalanced($journal);

        return $journal;
    }

    private function purchaseClearingAccount(int $tenantId): Account
    {
        $settings = BusinessSetting::query()
            ->forTenant($tenantId)
            ->first();

        $accountId = $settings?->purchase_clearing_account_id;
        if (! $accountId) {
            throw new ApiException(
                'PURCHASE_CLEARING_ACCOUNT_REQUIRED',
                'Configure a purchase clearing account in business settings before posting purchase returns.',
                422,
            );
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->whereKey($accountId)
            ->first();

        if (! $account || ! $account->is_active) {
            throw new ApiException(
                'PURCHASE_CLEARING_ACCOUNT_REQUIRED',
                'Configured purchase clearing account is missing or inactive.',
                422,
            );
        }

        return $account;
    }
}
