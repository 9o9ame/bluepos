<?php

namespace App\Actions\Sales;

use App\Accounting\OpeningBalancePoster;
use App\Enums\JournalStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;

class PostSaleAccountingAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
    ) {}

    public function execute(Sale $sale): ?JournalEntry
    {
        $tenantId = $this->tenantContext->tenantId();

        if ((int) $sale->tenant_id !== $tenantId) {
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
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (! $existing->isPosted()) {
                throw new ApiException(
                    'SALE_ACCOUNTING_CONFLICT',
                    'An unfinished sales accounting journal already exists.',
                    409,
                );
            }

            $existing->load('lines');
            $this->poster->assertBalanced($existing);

            return $existing;
        }

        $netRevenue = bcsub(
            bcadd((string) $sale->subtotal, '0', 4),
            bcadd((string) $sale->discount_amount, '0', 4),
            4,
        );
        $tax = bcadd((string) $sale->tax_amount, '0', 4);
        $grandTotal = bcadd((string) $sale->grand_total, '0', 4);

        if (bccomp($netRevenue, '0', 4) < 0 || bccomp($tax, '0', 4) < 0 || bccomp($grandTotal, '0', 4) < 0) {
            throw new ApiException(
                'SALE_TOTAL_INVALID',
                'Sale totals cannot be negative when posting accounting.',
                422,
            );
        }

        if (bccomp(bcadd($netRevenue, $tax, 4), $grandTotal, 4) !== 0) {
            throw new ApiException(
                'SALE_TOTAL_INVALID',
                'Sale accounting totals do not reconcile to the posted grand total.',
                422,
            );
        }

        $clearing = $this->requiredAccount(
            $settings?->sales_clearing_account_id,
            $tenantId,
            'SALES_CLEARING_ACCOUNT_REQUIRED',
            'Configure a sales clearing account in business settings before posting sales accounting.',
        );
        $revenue = $this->requiredAccount(
            $settings?->sales_revenue_account_id,
            $tenantId,
            'SALES_REVENUE_ACCOUNT_REQUIRED',
            'Configure a Sales Revenue account in business settings before posting sales accounting.',
        );
        $inventory = $this->requiredAccount(
            $settings?->inventory_account_id,
            $tenantId,
            'INVENTORY_ACCOUNT_REQUIRED',
            'Configure an Inventory Control account in business settings before posting sales accounting.',
        );
        $cogs = $this->requiredAccount(
            $settings?->cogs_account_id,
            $tenantId,
            'COGS_ACCOUNT_REQUIRED',
            'Configure a Cost of Goods Sold account in business settings before posting sales accounting.',
        );

        $taxPayable = null;
        if (bccomp($tax, '0', 4) > 0) {
            $taxPayable = $this->requiredAccount(
                $settings?->sales_tax_payable_account_id,
                $tenantId,
                'SALES_TAX_PAYABLE_ACCOUNT_REQUIRED',
                'Configure a Sales Tax Payable account in business settings before posting taxable sales.',
            );
        }

        $ids = array_filter([
            $clearing->id,
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
            ->where('warehouse_id', $sale->warehouse_id)
            ->where('movement_type', StockMovementType::Sale->value)
            ->where('reference_type', 'sale')
            ->where('reference_ulid', $sale->ulid)
            ->lockForUpdate()
            ->get();

        $cogsAmount = '0.0000';
        foreach ($movements as $movement) {
            if (bccomp((string) $movement->quantity, '0', 6) === 0) {
                continue;
            }

            if ($movement->unit_cost === null || $movement->total_cost === null) {
                throw new ApiException(
                    'SALE_COST_REQUIRED',
                    'A sale stock movement is missing its weighted-average cost snapshot.',
                    422,
                );
            }

            $movementCost = bcadd((string) $movement->total_cost, '0', 4);
            if (bccomp($movementCost, '0', 4) < 0) {
                $movementCost = bcsub('0.0000', $movementCost, 4);
            }

            $cogsAmount = bcadd($cogsAmount, $movementCost, 4);
        }

        $journal = JournalEntry::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $sale->branch_id,
            'document_type' => JournalEntry::DOCUMENT_SALE_INVOICE,
            'document_id' => $sale->id,
            'idempotency_key' => 'sale-accounting:'.$sale->ulid,
            'entry_date' => $sale->sale_date?->toDateString() ?? now()->toDateString(),
            'description' => 'Sales invoice '.$sale->document_number,
            'status' => JournalStatus::Posted,
            'created_by' => $this->tenantContext->userId(),
            'posted_by' => $this->tenantContext->userId(),
            'posted_at' => now(),
        ]);

        $sort = 0;

        if (bccomp($grandTotal, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $clearing->id,
                'customer_id' => $sale->customer_id,
                'description' => 'Sales clearing',
                'debit' => $grandTotal,
                'credit' => '0.0000',
                'sort_order' => $sort++,
            ]);
        }

        if (bccomp($netRevenue, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $revenue->id,
                'description' => 'Sales revenue',
                'debit' => '0.0000',
                'credit' => $netRevenue,
                'sort_order' => $sort++,
            ]);
        }

        if ($taxPayable && bccomp($tax, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $taxPayable->id,
                'description' => 'Sales tax payable',
                'debit' => '0.0000',
                'credit' => $tax,
                'sort_order' => $sort++,
            ]);
        }

        if (bccomp($cogsAmount, '0', 4) > 0) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $cogs->id,
                'description' => 'Cost of goods sold',
                'debit' => $cogsAmount,
                'credit' => '0.0000',
                'sort_order' => $sort++,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $inventory->id,
                'description' => 'Inventory relief',
                'debit' => '0.0000',
                'credit' => $cogsAmount,
                'sort_order' => $sort++,
            ]);
        }

        $journal->load('lines');
        $this->poster->assertBalanced($journal);

        return $journal;
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
