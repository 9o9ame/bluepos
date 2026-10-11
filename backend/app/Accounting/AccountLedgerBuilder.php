<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalLine;

class AccountLedgerBuilder
{
    public function __construct(private readonly AccountBalanceCalculator $balances) {}

    /**
     * @return array{
     *   account: array{ulid: string, code: string, name: string, address: string|null, area: string|null},
     *   rows: list<array<string, mixed>>,
     *   totals: array{debit: string, credit: string, closing_balance: string},
     *   pagination: array{page: int, per_page: int, total: int, last_page: int},
     *   carry_forward: string
     * }
     */
    public function build(
        Account $leaf,
        int $page = 1,
        int $perPage = 100,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $includeOpeningBalance = true,
        array $documentTypes = [],
        ?string $groupBy = null,
        ?string $filterBy = null,
        ?string $onlyFor = null,
        ?string $saleMode = null,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $leaf->loadMissing(['accountType', 'supplier', 'customer']);
        $accountArea = $leaf->area
            ?? $leaf->supplier?->area
            ?? $leaf->customer?->area;

        $lines = JournalLine::query()
            ->select([
                'journal_lines.ulid',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description as line_description',
                'journal_lines.sort_order',
                'journal_entries.ulid as entry_ulid',
                'journal_entries.entry_date',
                'journal_entries.voucher_number',
                'journal_entries.book_number',
                'journal_entries.document_type',
                'journal_entries.description as entry_description',
                'users.ulid as creator_ulid',
                'users.name as creator_name',
                'sales.price_type as sale_price_type',
                'salesman_profiles.ulid as salesman_ulid',
                'salesman_profiles.name as salesman_name',
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
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
            ->where('journal_lines.account_id', $leaf->id)
            ->where('journal_lines.tenant_id', $leaf->tenant_id)
            ->where('journal_entries.tenant_id', $leaf->tenant_id)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->when(
                ! $includeOpeningBalance,
                fn ($query) => $query->where('journal_entries.document_type', '!=', 'opening_balance'),
            )
            ->when(
                $documentTypes !== [],
                fn ($query) => $query->whereIn('journal_entries.document_type', $documentTypes),
            )
            ->orderBy('journal_entries.entry_date')
            ->orderByRaw('COALESCE(journal_entries.voucher_number, journal_entries.ulid)')
            ->orderBy('journal_lines.sort_order')
            ->orderBy('journal_lines.ulid')
            ->get();

        $filterOptions = $this->filterOptions($lines, $leaf, $accountArea);

        $runningBalance = '0.0000';
        $periodDebit = '0.0000';
        $periodCredit = '0.0000';
        $periodRows = [];
        $openingCarry = '0.0000';
        $subgroupBalance = '0.0000';
        $previousGroupKey = null;

        foreach ($lines as $line) {
            if (! $this->matchesFilter($line, $accountArea, $filterBy, $onlyFor)) {
                continue;
            }

            if (! $this->matchesSaleMode($line, $saleMode)) {
                continue;
            }

            $debit = $this->balances->money((string) $line->debit);
            $credit = $this->balances->money((string) $line->credit);
            $runningBalance = bcadd(
                $runningBalance,
                $this->balances->signedEffect($leaf, $debit, $credit),
                4,
            );

            $entryDate = \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d');

            if ($dateFrom !== null && $entryDate < $dateFrom) {
                $openingCarry = $this->balances->money($runningBalance);

                continue;
            }

            if ($dateTo !== null && $entryDate > $dateTo) {
                continue;
            }

            $periodDebit = bcadd($periodDebit, $debit, 4);
            $periodCredit = bcadd($periodCredit, $credit, 4);

            $groupKey = $this->groupKey($line, $accountArea, $groupBy);
            if ($groupBy !== null) {
                if ($groupKey !== $previousGroupKey) {
                    $subgroupBalance = '0.0000';
                    $previousGroupKey = $groupKey;
                }
                $subgroupBalance = bcadd(
                    $subgroupBalance,
                    $this->balances->signedEffect($leaf, $debit, $credit),
                    4,
                );
            }

            $periodRows[] = [
                'line_ulid' => $line->ulid,
                'trans_no' => $line->voucher_number ?: $line->entry_ulid,
                'date' => $entryDate,
                'doc' => $this->documentLabel((string) $line->document_type),
                'book_number' => $line->book_number,
                'remarks' => $line->line_description ?: $line->entry_description,
                'line_description' => $line->line_description,
                'entry_description' => $line->entry_description,
                'salesman_name' => $line->salesman_name,
                'sale_price_type' => $line->sale_price_type,
                'group_key' => $groupKey,
                'group_label' => $this->groupLabel($line, $accountArea, $groupBy),
                'subgroup_balance' => $groupBy !== null ? $this->balances->money($subgroupBalance) : null,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $this->balances->money($runningBalance),
            ];
        }

        $groupTotals = [];
        if ($groupBy !== null) {
            foreach ($periodRows as $row) {
                $key = (string) ($row['group_key'] ?? '');
                $label = (string) ($row['group_label'] ?? '');
                if ($key === '') {
                    continue;
                }

                if (! isset($groupTotals[$key])) {
                    $groupTotals[$key] = [
                        'key' => $key,
                        'label' => $label,
                        'debit' => '0.0000',
                        'credit' => '0.0000',
                    ];
                }

                $groupTotals[$key]['debit'] = bcadd($groupTotals[$key]['debit'], (string) $row['debit'], 4);
                $groupTotals[$key]['credit'] = bcadd($groupTotals[$key]['credit'], (string) $row['credit'], 4);
            }
        }

        $total = count($periodRows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $carryForward = $openingCarry;
        if ($offset > 0) {
            $carryForward = $periodRows[$offset - 1]['balance'];
        }

        $pageRows = array_slice($periodRows, $offset, $perPage);
        $closingBalance = $periodRows !== []
            ? $periodRows[array_key_last($periodRows)]['balance']
            : $openingCarry;

        return [
            'account' => [
                'ulid' => $leaf->ulid,
                'code' => $leaf->code,
                'name' => $leaf->name,
                'address' => $leaf->address,
                'area' => $accountArea,
            ],
            'rows' => array_values($pageRows),
            'filter_options' => $filterOptions,
            'group_totals' => array_values($groupTotals),
            'totals' => [
                'debit' => $periodDebit,
                'credit' => $periodCredit,
                'closing_balance' => $this->balances->money($closingBalance),
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'carry_forward' => $this->balances->money($carryForward),
        ];
    }

    private function matchesFilter(object $line, ?string $accountArea, ?string $filterBy, ?string $onlyFor): bool
    {
        if ($filterBy === null || $onlyFor === null || $onlyFor === '') {
            return true;
        }

        return match ($filterBy) {
            'document' => (string) $line->document_type === $onlyFor,
            'entry_date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d') === $onlyFor,
            'user_name' => (string) ($line->creator_ulid ?? '') === $onlyFor,
            'area_name' => (string) ($accountArea ?? '') === $onlyFor,
            'sales_person' => (string) ($line->salesman_ulid ?? '') === $onlyFor,
            default => true,
        };
    }

    private function matchesSaleMode(object $line, ?string $saleMode): bool
    {
        if ($saleMode === null || $saleMode === '') {
            return true;
        }

        if (! $line->sale_price_type) {
            return false;
        }

        return $saleMode === 'wholesale'
            ? (string) $line->sale_price_type === 'wholesale'
            : (string) $line->sale_price_type !== 'wholesale';
    }

    private function groupKey(object $line, ?string $accountArea, ?string $groupBy): ?string
    {
        return match ($groupBy) {
            'document' => (string) $line->document_type,
            'entry_date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d'),
            'user_name' => $line->creator_ulid ? (string) $line->creator_ulid : null,
            'area_name' => $accountArea ?: null,
            'sales_person' => $line->salesman_ulid ? (string) $line->salesman_ulid : 'none',
            default => null,
        };
    }

    private function groupLabel(object $line, ?string $accountArea, ?string $groupBy): ?string
    {
        return match ($groupBy) {
            'document' => $this->documentLabel((string) $line->document_type),
            'entry_date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d'),
            'user_name' => $line->creator_name ? (string) $line->creator_name : 'Unknown User',
            'area_name' => $accountArea ?: 'No Area',
            'sales_person' => $line->salesman_name ? (string) $line->salesman_name : 'No Sales Person',
            default => null,
        };
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $lines
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function filterOptions($lines, Account $leaf, ?string $accountArea): array
    {
        $documents = [];
        $dates = [];
        $users = [];
        $salesPeople = [];

        foreach ($lines as $line) {
            $documentType = (string) $line->document_type;
            $documents[$documentType] = $this->documentLabel($documentType);

            $date = \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d');
            $dates[$date] = $date;

            if ($line->creator_ulid) {
                $users[(string) $line->creator_ulid] = (string) ($line->creator_name ?: 'Unknown User');
            }

            if ($line->salesman_ulid) {
                $salesPeople[(string) $line->salesman_ulid] = (string) ($line->salesman_name ?: 'Unknown Sales Person');
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

        $areas = [];
        if ($accountArea) {
            $areas[$accountArea] = $accountArea;
        }

        return [
            'document' => $toOptions($documents),
            'entry_date' => $toOptions($dates),
            'user_name' => $toOptions($users),
            'area_name' => $toOptions($areas),
            'sales_person' => $toOptions($salesPeople),
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
