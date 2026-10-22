<?php

namespace App\Actions\Inventory;

use App\Accounting\OpeningBalancePoster;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Tenancy\TenantContext;

class PostInventoryAdjustmentAmendmentAccountingAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
    ) {}

    /**
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $lines
     */
    public function execute(
        InventoryAdjustment $document,
        array $header,
        array $lines,
        string $idempotencyKey,
        int $revisionNumber,
    ): ?JournalEntry {
        $tenantId = $this->tenantContext->tenantId();

        $existing = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $existing->load('lines');
            $this->poster->assertBalanced($existing);

            return $existing;
        }

        /** @var Account $offsetAccount */
        $offsetAccount = $header['account_model'];

        if (! $offsetAccount->is_active || (int) $offsetAccount->tenant_id !== $tenantId) {
            throw new ApiException(
                'ADJUSTMENT_ACCOUNT_REQUIRED',
                'Select an active adjustment account before amending this adjustment.',
                422,
            );
        }

        $settings = BusinessSetting::query()->forTenant($tenantId)->first();
        $inventoryAccount = $settings?->inventory_account_id
            ? Account::query()
                ->forTenant($tenantId)
                ->whereKey((int) $settings->inventory_account_id)
                ->where('is_active', true)
                ->first()
            : null;

        if (! $inventoryAccount) {
            throw new ApiException(
                'INVENTORY_ACCOUNT_REQUIRED',
                'Configure an active Inventory Control account before amending inventory adjustments.',
                422,
            );
        }

        if ((int) $inventoryAccount->id === (int) $offsetAccount->id) {
            throw new ApiException(
                'INVENTORY_ADJUSTMENT_CONFIGURATION_INVALID',
                'Inventory Control and the selected adjustment account must be different.',
                422,
            );
        }

        $accountingRows = [];

        foreach ($lines as $line) {
            $amount = bcadd((string) ($line['amount'] ?? '0'), '0', 4);
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $accountingRows[] = [
                'product_name' => (string) ($line['product']['name'] ?? 'Product'),
                'inbound' => bccomp((string) $line['quantity_delta'], '0', 6) > 0,
                'amount' => $amount,
            ];
        }

        if ($accountingRows === []) {
            return null;
        }

        $journal = JournalEntry::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $document->branch_id,
            'document_type' => JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT_AMENDMENT,
            'document_id' => $document->id,
            'idempotency_key' => $idempotencyKey,
            'entry_date' => now()->toDateString(),
            'description' => 'Inventory adjustment '.$document->document_number.' amendment '.$revisionNumber,
            'status' => JournalStatus::Posted,
            'created_by' => $this->tenantContext->userId(),
            'posted_by' => $this->tenantContext->userId(),
            'posted_at' => now(),
        ]);

        $sort = 0;

        foreach ($accountingRows as $row) {
            $amount = $row['amount'];
            $inbound = $row['inbound'];
            $description = 'Inventory adjustment - '.$row['product_name'];

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $inventoryAccount->id,
                'description' => $description,
                'debit' => $inbound ? $amount : '0.0000',
                'credit' => $inbound ? '0.0000' : $amount,
                'sort_order' => $sort++,
            ]);

            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $offsetAccount->id,
                'description' => $description,
                'debit' => $inbound ? '0.0000' : $amount,
                'credit' => $inbound ? $amount : '0.0000',
                'sort_order' => $sort++,
            ]);
        }

        $journal->load('lines');
        $this->poster->assertBalanced($journal);

        return $journal;
    }
}
