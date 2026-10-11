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
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $leaf->loadMissing('accountType');

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
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('users', 'users.id', '=', 'journal_entries.created_by')
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

        $filterOptions = $this->filterOptions($lines, $leaf);

        $runningBalance = '0.0000';
        $periodDebit = '0.0000';
        $periodCredit = '0.0000';
        $periodRows = [];
        $openingCarry = '0.0000';

        foreach ($lines as $line) {
            if (! $this->matchesFilter($line, $leaf, $filterBy, $onlyFor)) {
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

            $periodRows[] = [
                'line_ulid' => $line->ulid,
                'trans_no' => $line->voucher_number ?: $line->entry_ulid,
                'date' => $entryDate,
                'doc' => $this->documentLabel((string) $line->document_type),
                'book_number' => $line->book_number,
                'remarks' => $line->line_description ?: $line->entry_description,
                'group_key' => $this->groupKey($line, $leaf, $groupBy),
                'group_label' => $this->groupLabel($line, $leaf, $groupBy),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $this->balances->money($runningBalance),
            ];
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
                'area' => $leaf->area,
            ],
            'rows' => array_values($pageRows),
            'filter_options' => $filterOptions,
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

    private function matchesFilter(object $line, Account $leaf, ?string $filterBy, ?string $onlyFor): bool
    {
        if ($filterBy === null || $onlyFor === null || $onlyFor === '') {
            return true;
        }

        return match ($filterBy) {
            'document' => (string) $line->document_type === $onlyFor,
            'entry_date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d') === $onlyFor,
            'user_name' => (string) ($line->creator_ulid ?? '') === $onlyFor,
            'area_name' => (string) ($leaf->area ?? '') === $onlyFor,
            default => true,
        };
    }

    private function groupKey(object $line, Account $leaf, ?string $groupBy): ?string
    {
        return match ($groupBy) {
            'document' => (string) $line->document_type,
            'entry_date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d'),
            'user_name' => $line->creator_ulid ? (string) $line->creator_ulid : null,
            'area_name' => $leaf->area ? (string) $leaf->area : null,
            default => null,
        };
    }

    private function groupLabel(object $line, Account $leaf, ?string $groupBy): ?string
    {
        return match ($groupBy) {
            'document' => $this->documentLabel((string) $line->document_type),
            'entry_date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d'),
            'user_name' => $line->creator_name ? (string) $line->creator_name : 'Unknown User',
            'area_name' => $leaf->area ? (string) $leaf->area : 'No Area',
            default => null,
        };
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $lines
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function filterOptions($lines, Account $leaf): array
    {
        $documents = [];
        $dates = [];
        $users = [];

        foreach ($lines as $line) {
            $documentType = (string) $line->document_type;
            $documents[$documentType] = $this->documentLabel($documentType);

            $date = \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d');
            $dates[$date] = $date;

            if ($line->creator_ulid) {
                $users[(string) $line->creator_ulid] = (string) ($line->creator_name ?: 'Unknown User');
            }
        }

        ksort($documents);
        ksort($dates);
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);

        $toOptions = static fn (array $items): array => array_values(array_map(
            static fn (string $label, string $value): array => ['value' => $value, 'label' => $label],
            $items,
            array_keys($items),
        ));

        $areas = [];
        if ($leaf->area) {
            $areas[(string) $leaf->area] = (string) $leaf->area;
        }

        return [
            'document' => $toOptions($documents),
            'entry_date' => $toOptions($dates),
            'user_name' => $toOptions($users),
            'area_name' => $toOptions($areas),
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
