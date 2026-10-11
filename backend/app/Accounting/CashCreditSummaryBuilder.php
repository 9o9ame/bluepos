<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

class CashCreditSummaryBuilder
{
    public function __construct(
        private readonly AccountBalanceCalculator $balances,
        private readonly CashBookReportBuilder $cashBook,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, int $branchId, string $date): array
    {
        $cashAccounts = $this->cashBook->cashAccounts($tenantId);
        $cashAccountIds = $cashAccounts->pluck('id')->map(fn ($id) => (int) $id)->all();

        $metricDefinitions = [
            'sales' => ['supported' => false, 'types' => []],
            'retail_sale' => ['supported' => false, 'types' => []],
            'sale_return' => ['supported' => false, 'types' => []],
            'purchase' => ['supported' => true, 'types' => [JournalEntry::DOCUMENT_PURCHASE_INVOICE]],
            'purchase_return' => ['supported' => true, 'types' => [JournalEntry::DOCUMENT_PURCHASE_RETURN]],
            'recovery' => ['supported' => true, 'types' => ['sale_payment']],
            'payment' => ['supported' => true, 'types' => [
                JournalEntry::DOCUMENT_PAYMENT_VOUCHER,
                JournalEntry::DOCUMENT_PURCHASE_PAYMENT,
            ]],
            'receivings' => ['supported' => true, 'types' => [JournalEntry::DOCUMENT_RECEIVING_VOUCHER]],
        ];

        $metrics = [];
        foreach ($metricDefinitions as $key => $definition) {
            if (! $definition['supported']) {
                $metrics[$key] = [
                    'supported' => false,
                    'cash' => null,
                    'credit' => null,
                ];
                continue;
            }

            $entries = JournalEntry::query()
                ->forTenant($tenantId)
                ->where('branch_id', $branchId)
                ->where('status', JournalStatus::Posted->value)
                ->whereDate('entry_date', $date)
                ->whereIn('document_type', $definition['types'])
                ->with('lines')
                ->orderBy('id')
                ->get();

            $cash = '0.0000';
            $credit = '0.0000';

            foreach ($entries as $entry) {
                $documentTotal = $this->documentTotal($entry->lines);
                $touchesCashOrBank = $entry->lines->contains(
                    fn (JournalLine $line): bool => in_array((int) $line->account_id, $cashAccountIds, true),
                );

                if ($touchesCashOrBank) {
                    $cash = bcadd($cash, $documentTotal, 4);
                } else {
                    $credit = bcadd($credit, $documentTotal, 4);
                }
            }

            $metrics[$key] = [
                'supported' => true,
                'cash' => $this->balances->money($cash),
                'credit' => $this->balances->money($credit),
            ];
        }

        $positions = $cashAccounts
            ->map(fn (Account $account): array => $this->positionRow(
                $account,
                $tenantId,
                $branchId,
                $date,
            ))
            ->values()
            ->all();

        $totals = [
            'opening' => '0.0000',
            'debit' => '0.0000',
            'credit' => '0.0000',
            'closing' => '0.0000',
        ];

        foreach ($positions as $row) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] = bcadd($totals[$field], (string) $row[$field], 4);
            }
        }

        return [
            'date' => $date,
            'metrics' => $metrics,
            'positions' => $positions,
            'totals' => array_map(
                fn (string $value): string => $this->balances->money($value),
                $totals,
            ),
            'unsupported_reasons' => [
                'sales' => 'Sales Invoice revenue/COGS accounting journals are not implemented yet.',
                'retail_sale' => 'Retail Sale cannot be derived authoritatively until Sales Invoice accounting journals exist.',
                'sale_return' => 'Sales Return accounting reversal journals are not implemented yet.',
            ],
        ];
    }

    /**
     * @param Collection<int, JournalLine> $lines
     */
    private function documentTotal(Collection $lines): string
    {
        return $this->balances->money($lines->reduce(
            fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->debit, 4),
            '0.0000',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function positionRow(Account $account, int $tenantId, int $branchId, string $date): array
    {
        $opening = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '<', $date)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();

        $period = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', $date)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();

        $openingDebit = $this->balances->money((string) ($opening?->debit ?? '0'));
        $openingCredit = $this->balances->money((string) ($opening?->credit ?? '0'));
        $debit = $this->balances->money((string) ($period?->debit ?? '0'));
        $credit = $this->balances->money((string) ($period?->credit ?? '0'));
        $openingBalance = $this->balances->signedEffect($account, $openingDebit, $openingCredit);
        $periodEffect = $this->balances->signedEffect($account, $debit, $credit);
        $closing = bcadd($openingBalance, $periodEffect, 4);

        return [
            'account' => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->accountType?->name,
            ],
            'opening' => $this->balances->money($openingBalance),
            'debit' => $debit,
            'credit' => $credit,
            'closing' => $this->balances->money($closing),
        ];
    }
}
