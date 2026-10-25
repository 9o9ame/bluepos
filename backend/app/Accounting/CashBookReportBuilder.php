<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

class CashBookReportBuilder
{
    public function __construct(
        private readonly AccountBalanceCalculator $balances,
    ) {}

    /**
     * @return Collection<int, Account>
     */
    public function cashAccounts(int $tenantId): Collection
    {
        return Account::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->whereHas('accountType', fn ($type) => $type
                ->where('is_cash', true)
                ->orWhere('is_bank', true)
                ->orWhereIn('code', ['0010', '0012']))
            ->with('accountType')
            ->orderBy('code')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        Account $account,
        int $tenantId,
        int $branchId,
        ?string $dateFrom,
        ?string $dateTo,
        bool $ignoreDate = false,
    ): array {
        $account->loadMissing('accountType');

        $openingDebit = '0.0000';
        $openingCredit = '0.0000';

        if (! $ignoreDate && $dateFrom !== null) {
            $opening = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.tenant_id', $tenantId)
                ->where('journal_lines.account_id', $account->id)
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.branch_id', $branchId)
                ->where('journal_entries.status', JournalStatus::Posted->value)
                ->whereDate('journal_entries.entry_date', '<', $dateFrom)
                ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
                ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
                ->first();

            $openingDebit = $this->balances->money((string) ($opening?->debit ?? '0'));
            $openingCredit = $this->balances->money((string) ($opening?->credit ?? '0'));
        }

        $openingBalance = $this->balances->signedEffect($account, $openingDebit, $openingCredit);

        $query = JournalLine::query()
            ->select([
                'journal_lines.ulid as line_ulid',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description as line_description',
                'journal_lines.sort_order',
                'journal_entries.entry_date',
                'journal_entries.voucher_number',
                'journal_entries.document_type',
                'journal_entries.description as entry_description',
                'journal_entries.document_type as source_document_type',
                'journal_entries.document_id as source_document_id',
                'journal_entries.ulid as source_journal_ulid',
                'users.name as creator_name',
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('users', 'users.id', '=', 'journal_entries.created_by')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value);

        if (! $ignoreDate) {
            if ($dateFrom !== null) {
                $query->whereDate('journal_entries.entry_date', '>=', $dateFrom);
            }
            if ($dateTo !== null) {
                $query->whereDate('journal_entries.entry_date', '<=', $dateTo);
            }
        }

        $lines = $query
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.sort_order')
            ->orderBy('journal_lines.ulid')
            ->get();

        $periodDebit = '0.0000';
        $periodCredit = '0.0000';
        $runningBalance = $openingBalance;
        $rows = [];

        foreach ($lines as $line) {
            $debit = $this->balances->money((string) $line->debit);
            $credit = $this->balances->money((string) $line->credit);
            $periodDebit = bcadd($periodDebit, $debit, 4);
            $periodCredit = bcadd($periodCredit, $credit, 4);
            $runningBalance = bcadd(
                $runningBalance,
                $this->balances->signedEffect($account, $debit, $credit),
                4,
            );

            $rows[] = [
                'line_ulid' => (string) $line->line_ulid,
                'trans_no' => $line->voucher_number ?: '-',
                'date' => $line->entry_date
                    ? \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d')
                    : null,
                'doc' => $this->documentLabel((string) $line->document_type),
                'creator_name' => $line->creator_name ?: '(NONE)',
                // These source keys are consumed inside the report controller and
                // removed before sending the public response.
                '_source_type' => $line->source_document_type,
                '_source_id' => $line->source_document_id,
                '_journal_ulid' => $line->source_journal_ulid,
                'description' => $line->line_description
                    ?: $line->entry_description
                    ?: $this->documentLabel((string) $line->document_type),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $this->balances->money($runningBalance),
            ];
        }

        $periodEffect = $this->balances->signedEffect($account, $periodDebit, $periodCredit);
        $closingBalance = bcadd($openingBalance, $periodEffect, 4);

        return [
            'account' => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->accountType?->name,
                'is_cash' => (bool) ($account->accountType?->is_cash || $account->accountType?->code === '0010'),
                'is_bank' => (bool) ($account->accountType?->is_bank || $account->accountType?->code === '0012'),
            ],
            'date_from' => $ignoreDate ? null : $dateFrom,
            'date_to' => $ignoreDate ? null : $dateTo,
            'ignore_date' => $ignoreDate,
            'rows' => $rows,
            'summary' => [
                'opening' => $this->balances->money($openingBalance),
                'received' => $this->balances->money($periodDebit),
                'paid' => $this->balances->money($periodCredit),
                'balance' => $this->balances->money($periodEffect),
                'closing' => $this->balances->money($closingBalance),
            ],
        ];
    }

    private function documentLabel(string $documentType): string
    {
        return match ($documentType) {
            'opening_balance' => 'OPENING VOUCHER',
            'payment_voucher' => 'PAYMENT VOUCHER',
            'receiving_voucher' => 'RECEIVING VOUCHER',
            'journal_voucher' => 'JOURNAL VOUCHER',
            'purchase_invoice' => 'PURCHASE INVOICE',
            'purchase_return' => 'PURCHASE RETURN',
            'purchase_payment' => 'PURCHASE PAYMENT',
            'sale_payment' => 'SALE PAYMENT',
            default => strtoupper(str_replace('_', ' ', $documentType)),
        };
    }
}
