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
    public function build(
        int $tenantId,
        int $branchId,
        string $date,
        ?string $filterBy = null,
        ?string $onlyFor = null,
    ): array {
        $cashAccounts = $this->cashBook->cashAccounts($tenantId);
        $cashAccountIds = $cashAccounts->pluck('id')->map(fn ($id) => (int) $id)->all();

        $entries = JournalEntry::query()
            ->select([
                'journal_entries.*',
                'users.ulid as creator_ulid',
                'users.name as creator_name',
                'salesman_profiles.ulid as salesman_ulid',
                'salesman_profiles.name as salesman_name',
            ])
            ->leftJoin('users', 'users.id', '=', 'journal_entries.created_by')
            ->leftJoin('sales', function ($join): void {
                $join->on('sales.id', '=', 'journal_entries.document_id')
                    ->on('sales.tenant_id', '=', 'journal_entries.tenant_id')
                    ->where('journal_entries.document_type', '=', 'sale_payment');
            })
            ->leftJoin('party_profiles as salesman_profiles', function ($join): void {
                $join->on('salesman_profiles.id', '=', 'sales.salesman_party_profile_id')
                    ->on('salesman_profiles.tenant_id', '=', 'journal_entries.tenant_id');
            })
            ->forTenant($tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', $date)
            ->with('lines')
            ->orderBy('journal_entries.id')
            ->get();

        $filterOptions = $this->filterOptions($entries);
        $filterActive = $filterBy !== null && $onlyFor !== null && $onlyFor !== '';
        $filteredEntries = $filterActive
            ? $entries->filter(fn (JournalEntry $entry): bool => $this->matchesFilter($entry, $filterBy, $onlyFor))->values()
            : $entries;

        $filteredEntryIds = $filteredEntries
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

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

            $metricEntries = $filteredEntries->filter(
                fn (JournalEntry $entry): bool => in_array((string) $entry->document_type, $definition['types'], true),
            );

            $cash = '0.0000';
            $credit = '0.0000';

            foreach ($metricEntries as $entry) {
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
                $filterActive,
                $filteredEntryIds,
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
            'filter_by' => $filterBy,
            'only_for' => $onlyFor,
            'filter_options' => $filterOptions,
            'metrics' => $metrics,
            'positions' => $positions,
            'totals' => array_map(
                fn (string $value): string => $this->balances->money($value),
                $totals,
            ),
            'unsupported_filters' => [
                'area_name' => 'Cash/Credit Summary has no single authoritative journal-level area source across vouchers, purchases, and sale payments.',
                'main_area_name' => 'Main Area is not stored as an authoritative journal dimension.',
                'category_name' => 'Category is not stored as an authoritative journal dimension.',
            ],
            'unsupported_reasons' => [
                'sales' => 'Sales Invoice revenue/COGS accounting journals are not implemented yet.',
                'retail_sale' => 'Retail Sale cannot be derived authoritatively until Sales Invoice accounting journals exist.',
                'sale_return' => 'Sales Return accounting reversal journals are not implemented yet.',
            ],
        ];
    }

    private function matchesFilter(JournalEntry $entry, ?string $filterBy, ?string $onlyFor): bool
    {
        if ($filterBy === null || $onlyFor === null || $onlyFor === '') {
            return true;
        }

        return match ($filterBy) {
            'document' => (string) $entry->document_type === $onlyFor,
            'entry_date' => $entry->entry_date?->format('Y-m-d') === $onlyFor,
            'user_name' => (string) ($entry->creator_ulid ?? '') === $onlyFor,
            'sales_person' => (string) ($entry->salesman_ulid ?? '') === $onlyFor,
            default => true,
        };
    }

    /**
     * @param Collection<int, JournalEntry> $entries
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function filterOptions(Collection $entries): array
    {
        $documents = [];
        $dates = [];
        $users = [];
        $salesPeople = [];

        foreach ($entries as $entry) {
            $documentType = (string) $entry->document_type;
            $documents[$documentType] = $this->documentLabel($documentType);

            if ($entry->entry_date) {
                $date = $entry->entry_date->format('Y-m-d');
                $dates[$date] = $date;
            }

            if ($entry->creator_ulid) {
                $users[(string) $entry->creator_ulid] = (string) ($entry->creator_name ?: 'Unknown User');
            }

            if ($entry->salesman_ulid) {
                $salesPeople[(string) $entry->salesman_ulid] = (string) ($entry->salesman_name ?: 'Unknown Sales Person');
            }
        }

        ksort($documents);
        ksort($dates);
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);
        asort($salesPeople, SORT_NATURAL | SORT_FLAG_CASE);

        $toOptions = static fn (array $items): array => array_values(array_map(
            static fn (string $label, string $value): array => ['value' => $value, 'label' => $label],
            $items,
            array_keys($items),
        ));

        return [
            'document' => $toOptions($documents),
            'entry_date' => $toOptions($dates),
            'user_name' => $toOptions($users),
            'sales_person' => $toOptions($salesPeople),
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
     * @param list<int> $filteredEntryIds
     * @return array<string, mixed>
     */
    private function positionRow(
        Account $account,
        int $tenantId,
        int $branchId,
        string $date,
        bool $filterActive,
        array $filteredEntryIds,
    ): array {
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

        $periodQuery = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', $date);

        if ($filterActive) {
            if ($filteredEntryIds === []) {
                $periodQuery->whereRaw('1 = 0');
            } else {
                $periodQuery->whereIn('journal_entries.id', $filteredEntryIds);
            }
        }

        $period = $periodQuery
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

    private function documentLabel(string $documentType): string
    {
        return match ($documentType) {
            'opening_balance' => 'Opening Balance',
            default => strtoupper(str_replace('_', ' ', $documentType)),
        };
    }
}
