<?php

namespace App\Actions\Accounting;

use App\Accounting\OpeningBalancePoster;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ReverseJournalEntryAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpeningBalancePoster $poster,
    ) {}

    public function execute(
        JournalEntry $journal,
        string $idempotencyKey,
        string $description,
    ): JournalEntry {
        $tenantId = $this->tenantContext->tenantId();
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid idempotency key is required to reverse a journal.',
                422,
            );
        }

        return DB::transaction(function () use (
            $journal,
            $idempotencyKey,
            $description,
            $tenantId,
        ): JournalEntry {
            $existing = JournalEntry::query()
                ->forTenant($tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load('lines');
            }

            $journal = JournalEntry::query()
                ->forTenant($tenantId)
                ->whereKey($journal->id)
                ->with('lines')
                ->lockForUpdate()
                ->first();

            if (! $journal || ! $journal->isPosted()) {
                throw new ApiException(
                    'JOURNAL_NOT_POSTED',
                    'Only a posted journal can be reversed.',
                    422,
                );
            }

            $this->poster->assertBalanced($journal);

            $reversal = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $journal->branch_id,
                'document_type' => $journal->document_type.'_reversal',
                'document_id' => $journal->document_id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => now()->toDateString(),
                'description' => $description,
                'status' => JournalStatus::Posted,
                'created_by' => $this->tenantContext->userId(),
                'posted_by' => $this->tenantContext->userId(),
                'posted_at' => now(),
                'reverses_journal_entry_id' => $journal->id,
            ]);

            foreach ($journal->lines->sortBy('sort_order')->values() as $index => $line) {
                JournalLine::query()->create([
                    'tenant_id' => $tenantId,
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'description' => 'Reversal: '.($line->description ?? $journal->description ?? 'Journal line'),
                    'debit' => (string) $line->credit,
                    'credit' => (string) $line->debit,
                    'customer_id' => $line->customer_id,
                    'supplier_id' => $line->supplier_id,
                    'sort_order' => $index,
                ]);
            }

            $reversal->load('lines');
            $this->poster->assertBalanced($reversal);

            return $reversal;
        });
    }
}
