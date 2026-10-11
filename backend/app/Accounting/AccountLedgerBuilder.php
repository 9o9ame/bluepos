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
                'journal_entries.document_type',
                'journal_entries.description as entry_description',
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $leaf->id)
            ->where('journal_lines.tenant_id', $leaf->tenant_id)
            ->where('journal_entries.tenant_id', $leaf->tenant_id)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->when(
                ! $includeOpeningBalance,
                fn ($query) => $query->where('journal_entries.document_type', '!=', 'opening_balance'),
            )
            ->orderBy('journal_entries.entry_date')
            ->orderByRaw('COALESCE(journal_entries.voucher_number, journal_entries.ulid)')
            ->orderBy('journal_lines.sort_order')
            ->orderBy('journal_lines.ulid')
            ->get();

        $runningBalance = '0.0000';
        $periodDebit = '0.0000';
        $periodCredit = '0.0000';
        $periodRows = [];
        $openingCarry = '0.0000';

        foreach ($lines as $line) {
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
                'remarks' => $line->line_description ?: $line->entry_description,
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
                'area' => null,
            ],
            'rows' => array_values($pageRows),
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

    private function documentLabel(string $documentType): string
    {
        return match ($documentType) {
            'opening_balance' => 'Opening Balance',
            default => strtoupper(str_replace('_', ' ', $documentType)),
        };
    }
}
