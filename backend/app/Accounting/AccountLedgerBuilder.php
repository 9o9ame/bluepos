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
    public function build(Account $leaf, int $page = 1, int $perPage = 100): array
    {
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
            ->orderBy('journal_entries.entry_date')
            ->orderByRaw('COALESCE(journal_entries.voucher_number, journal_entries.ulid)')
            ->orderBy('journal_lines.sort_order')
            ->orderBy('journal_lines.ulid')
            ->get();

        $totalDebit = '0.0000';
        $totalCredit = '0.0000';
        $closing = '0.0000';
        $runningRows = [];

        foreach ($lines as $line) {
            $debit = $this->balances->money((string) $line->debit);
            $credit = $this->balances->money((string) $line->credit);
            $closing = bcadd($closing, $this->balances->signedEffect($leaf, $debit, $credit), 4);
            $totalDebit = bcadd($totalDebit, $debit, 4);
            $totalCredit = bcadd($totalCredit, $credit, 4);

            $runningRows[] = [
                'line_ulid' => $line->ulid,
                'trans_no' => $line->voucher_number ?: $line->entry_ulid,
                'date' => \Illuminate\Support\Carbon::parse($line->entry_date)->format('Y-m-d'),
                'doc' => $this->documentLabel((string) $line->document_type),
                'remarks' => $line->line_description ?: $line->entry_description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $this->balances->money($closing),
            ];
        }

        $total = count($runningRows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $carryForward = '0.0000';
        if ($offset > 0) {
            $carryForward = $runningRows[$offset - 1]['balance'];
        }

        $pageRows = array_slice($runningRows, $offset, $perPage);

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
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'closing_balance' => $this->balances->money($closing),
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
