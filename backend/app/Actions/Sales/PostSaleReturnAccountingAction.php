<?php

namespace App\Actions\Sales;

use App\Accounting\OpeningBalancePoster;
use App\Accounting\PartyLeafAccountSync;
use App\Enums\JournalStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;

class PostSaleReturnAccountingAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
        private readonly PartyLeafAccountSync $partyAccounts,
    ) {}

    public function execute(SaleReturn $document): ?JournalEntry
    {
        $tenantId = $this->tenantContext->tenantId();

        if ((int) $document->tenant_id !== $tenantId) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $settings = BusinessSetting::query()
            ->forTenant($tenantId)
            ->first();

        if (! $this->hasAnyAccountingControl($settings)) {
            return null;
        }

        $existing = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('document_type', JournalEntry::DOCUMENT_SALE_RETURN)
            ->where('document_id', $document->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (! $existing->isPosted()) {
                throw new ApiException(
                    'SALE_RETURN_ACCOUNTING_CONFLICT',
                    'An unfinished sales return accounting journal already exists.',
                    409,
                );
            }

            $existing->load('lines');
            $this->poster->assertBalanced($existing);

            return $existing;
        }

        $sale = $document->sale;
        if (! $sale) {
            throw new ApiException(
                'SALE_ACCOUNTING_REQUIRED',
                'The original sale is required before posting sales return accounting.',
                422,
            );
        }

        $originalJournal = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->where('status', JournalStatus::Posted->value)
            ->lockForUpdate()
            ->first();

        if (! $originalJournal) {
            throw new ApiException(
                'SALE_ACCOUNTING_REQUIRED',
                'The original sale must have posted accounting before its return can be posted.',
                422,
            );
        }

        $netRevenue = bcsub(
            bcadd((string) $document->subtotal, '0', 4),
            bcadd((string) $document->discount_amount, '0', 4),
            4,
        );
        $tax = bcadd((string) $document->tax_amount, '0', 4);
        $grandTotal = bcadd((string) $document->grand_total, '0', 4);

        if (bccomp($netRevenue, '0', 4) < 0 || bccomp($tax, '0', 4) < 0 || bccomp($grandTotal, '0', 4) < 0) {
            throw new ApiException(
                'SALE_RETURN_TOTAL_INVALID',
                'Sales return totals cannot be negative when posting accounting.',
                422,
            );
        }

        if (bccomp(bcadd($netRevenue, $tax, 4), $grandTotal, 4) !== 0) {
            throw new ApiException(
                'SALE_RETURN_TOTAL_INVALID',
                'Sales return accounting totals do not reconcile to the posted grand total.',
                422,
            );
        }

        $settlement = $this->settlementAccount($document, $settings, $tenantId);
        $revenue = $this->requiredAccount(
            $settings?->sales_revenue_account_id,
            $tenantId,
            'SALES_REVENUE_ACCOUNT_REQUIRED',
            'Configure a Sales Revenue account in business settings before posting sales return accounting.',
        );
        $inventory = $this->requiredAccount(
            $settings?->inventory_account_id,
            $tenantId,
            'INVENTORY_ACCOUNT_REQUIRED',
            'Configure an Inventory Control account in business settings before posting sales return accounting.',
        );
        $cogs = $this->requiredAccount(
            $settings?->cogs_account_id,
            $tenantId,
            'COGS_ACCOUNT_REQUIRED',
            'Configure a Cost of Goods Sold account in business settings before posting sales return accounting.',
        );

        $taxPayable = null;
        if (bccomp($tax, '0', 4) > 0) {
            $taxPayable = $this->requiredAccount(
                $settings?->sales_tax_payable_account_id,
                $tenantId,
                'SALES_TAX_PAYABLE_ACCOUNT_REQUIRED',
                'Configure a Sales Tax Payable account in business settings before posting taxable sales returns.',
            );
        }

        $ids = array_filter([
            $settlement->id,
            $revenue->id,
            $inventory->id,
            $cogs->id,
            $taxPayable?->id,
        ], static fn ($id): bool => $id !== null);

        if (count($ids) !== count(array_unique($ids))) {
            throw new ApiException(
                'SALE_ACCOUNTING_CONFIGURATION_INVALID',
                'Sales accounting control accounts must be distinct.',
                422,
            );
        }

        $movements = StockMovement::query()
            ->forTenant($tenantId)
            ->where('warehouse_id', $document->warehouse_id)
            ->where('movement_type', StockMovementType::SaleReturn->value)
            ->where('reference_type', 'sale_return')
            ->where('reference_ulid', $document->ulid)
            ->lockForUpdate()
            ->get();

        $returnedCost = '0.0000';
        foreach ($movements as $movement) {
            if ($movement->unit_cost === null || $movement->total_cost === null) {
                throw new ApiException(
                    'SALE_RETURN_COST_REQUIRED',
                    'A sales return stock movement is missing its original sale cost snapshot.',
                    422,
                );
            }

            $movementCost = bcadd((string) $movement->total_cost, '0', 4);
            if (bccomp($movementCost, '0', 4) < 0) {
                $movementCost = bcsub('0.0000', $movementCost, 4);
            }

            $returnedCost = bcadd($returnedCost, $movementCost, 4);
        }

        $journal = JournalEntry::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $document->branch_id,
            'document_type' => JournalEntry::DOCUMENT_SALE_RETURN,
            'document_id' => $document->id,
            'idempotency_key' => 'sale-return-accounting:'.$document->ulid,
            'entry_date' => $document->return_date?->toDateString() ?? now()->toDateString(),
            'description' => 'Sales return '.$document->document_number,
            'status' => JournalStatus::Posted,
            'created_by' => $this->tenantContext->userId(),
            'posted_by' => $this->tenantContext->userId(),
            'posted_at' => now(),
            'reverses_journal_entry_id' => $originalJournal->id,
        ]);

        $sort = 0;

        if (bccomp($netRevenue, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $revenue->id,
                'description' => 'Sales revenue reversal',
                'debit' => $netRevenue,
                'credit' => '0.0000',
                'sort_order' => $sort++,
            ]);
        }

        if ($taxPayable && bccomp($tax, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $taxPayable->id,
                'description' => 'Sales tax payable reversal',
                'debit' => $tax,
                'credit' => '0.0000',
                'sort_order' => $sort++,
            ]);
        }

        if (bccomp($grandTotal, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $settlement->id,
                'customer_id' => $document->customer_id,
                'description' => $document->customer_id ? 'Customer receivable reversal' : 'Sales clearing reversal',
                'debit' => '0.0000',
                'credit' => $grandTotal,
                'sort_order' => $sort++,
            ]);
        }

        if (bccomp($returnedCost, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $inventory->id,
                'description' => 'Inventory return',
                'debit' => $returnedCost,
                'credit' => '0.0000',
                'sort_order' => $sort++,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $cogs->id,
                'description' => 'Cost of goods sold reversal',
                'debit' => '0.0000',
                'credit' => $returnedCost,
                'sort_order' => $sort++,
            ]);
        }

        $journal->load('lines');
        $this->poster->assertBalanced($journal);

        return $journal;
    }

    private function settlementAccount(
        SaleReturn $document,
        ?BusinessSetting $settings,
        int $tenantId,
    ): Account {
        if ($document->customer_id !== null) {
            $customer = Customer::query()
                ->forTenant($tenantId)
                ->whereKey((int) $document->customer_id)
                ->with('accountType')
                ->first();

            if (! $customer || ! $customer->is_active || ! $customer->accountType?->is_receivable) {
                throw new ApiException(
                    'CUSTOMER_RECEIVABLE_ACCOUNT_REQUIRED',
                    'Configure the customer with an active Accounts Receivable account type before posting the sales return.',
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

        return $this->requiredAccount(
            $settings?->sales_clearing_account_id,
            $tenantId,
            'SALES_CLEARING_ACCOUNT_REQUIRED',
            'Configure a sales clearing account in business settings before posting walk-in sales return accounting.',
        );
    }

    private function hasAnyAccountingControl(?BusinessSetting $settings): bool
    {
        return (bool) (
            $settings?->sales_revenue_account_id
            || $settings?->sales_tax_payable_account_id
            || $settings?->inventory_account_id
            || $settings?->cogs_account_id
        );
    }

    private function requiredAccount(
        int|string|null $accountId,
        int $tenantId,
        string $errorKey,
        string $message,
    ): Account {
        if (! $accountId) {
            throw new ApiException($errorKey, $message, 422);
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->whereKey((int) $accountId)
            ->first();

        if (! $account || ! $account->is_active) {
            throw new ApiException($errorKey, $message, 422);
        }

        return $account;
    }
}
