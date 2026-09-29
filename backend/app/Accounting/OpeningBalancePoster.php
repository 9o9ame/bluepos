<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class OpeningBalancePoster
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public function post(JournalEntry $entry, Account $leafAccount): JournalEntry
    {
        return DB::transaction(function () use ($entry, $leafAccount): JournalEntry {
            $entry = JournalEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->with('lines')
                ->firstOrFail();

            if ($entry->isPosted()) {
                return $entry->load('lines');
            }

            if ($entry->document_type !== JournalEntry::DOCUMENT_OPENING_BALANCE) {
                throw new ApiException('VALIDATION_FAILED', 'Only opening balance journals can be posted here.', 422);
            }

            $partyLine = $entry->lines->first(
                fn (JournalLine $line) => (int) $line->account_id === (int) $leafAccount->id
            );

            if (! $partyLine) {
                throw new ApiException('VALIDATION_FAILED', 'Opening journal is missing the leaf account line.', 422);
            }

            $this->assertMoneySides((string) $partyLine->debit, (string) $partyLine->credit);

            $offsetAccount = $this->resolveOffsetAccount((int) $entry->tenant_id);
            if ((int) $offsetAccount->id === (int) $leafAccount->id) {
                throw new ApiException(
                    'VALIDATION_FAILED',
                    'Opening equity offset account cannot be the same as the leaf account.',
                    422,
                );
            }

            JournalLine::query()->create([
                'tenant_id' => $entry->tenant_id,
                'journal_entry_id' => $entry->id,
                'account_id' => $offsetAccount->id,
                'description' => 'Opening balance equity offset',
                'debit' => $partyLine->credit,
                'credit' => $partyLine->debit,
                'sort_order' => 1,
            ]);

            $entry->load('lines');
            $this->assertBalanced($entry);

            $entry->status = JournalStatus::Posted;
            $entry->posted_by = $this->tenantContext->userId();
            $entry->posted_at = now();
            $entry->save();

            $this->audit->record('OPENING_BALANCE_POSTED', [
                'resource_type' => 'journal_entry',
                'resource_ulid' => $entry->ulid,
            ]);

            return $entry->fresh(['lines']) ?? $entry;
        });
    }

    public function assertMoneySides(string $debit, string $credit): void
    {
        if (bccomp($debit, '0', 4) < 0 || bccomp($credit, '0', 4) < 0) {
            throw new ApiException('VALIDATION_FAILED', 'Debit and credit must be >= 0.', 422);
        }

        $debitPositive = bccomp($debit, '0', 4) === 1;
        $creditPositive = bccomp($credit, '0', 4) === 1;

        if ($debitPositive && $creditPositive) {
            throw new ApiException('VALIDATION_FAILED', 'Debit and credit cannot both be positive.', 422);
        }

        if (! $debitPositive && ! $creditPositive) {
            throw new ApiException('VALIDATION_FAILED', 'Opening amount requires debit or credit.', 422);
        }
    }

    public function assertBalanced(JournalEntry $entry): void
    {
        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($entry->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        if (bccomp($debit, $credit, 4) !== 0) {
            throw new ApiException('UNBALANCED_JOURNAL', 'Journal debits must equal credits.', 422);
        }
    }

    private function resolveOffsetAccount(int $tenantId): Account
    {
        $settings = BusinessSetting::query()->forTenant($tenantId)->first();
        $accountId = $settings?->opening_balance_equity_account_id;

        if (! $accountId) {
            throw new ApiException(
                'OPENING_EQUITY_ACCOUNT_REQUIRED',
                'Configure Opening Balance Equity account in business settings before posting.',
                422,
            );
        }

        $account = Account::query()
            ->forTenant($tenantId)
            ->whereKey($accountId)
            ->first();

        if (! $account || ! $account->is_active) {
            throw new ApiException(
                'OPENING_EQUITY_ACCOUNT_REQUIRED',
                'Configured Opening Balance Equity account is missing or inactive.',
                422,
            );
        }

        return $account;
    }
}
