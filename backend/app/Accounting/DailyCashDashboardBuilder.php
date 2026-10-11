<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DailyCashDashboardBuilder
{
    public function __construct(
        private readonly AccountBalanceCalculator $balances,
        private readonly CashBookReportBuilder $cashBook,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, int $branchId, string $date, int $days = 7): array
    {
        $days = max(1, min(14, $days));
        $end = CarbonImmutable::parse($date)->startOfDay();
        $start = $end->subDays($days - 1);

        $cashAccounts = $this->cashBook->cashAccounts($tenantId);
        $cashAccountIds = $cashAccounts->pluck('id')->map(fn ($id) => (int) $id)->all();

        $chart = $this->cashSeries(
            $cashAccounts,
            $cashAccountIds,
            $tenantId,
            $branchId,
            $start,
            $end,
        );

        $balances = $this->accountBalances($tenantId, $branchId, $end->toDateString());

        return [
            'date' => $end->toDateString(),
            'days' => $days,
            'chart' => $chart,
            'debit_accounts' => $balances['debit_accounts'],
            'credit_accounts' => $balances['credit_accounts'],
            'totals' => $balances['totals'],
        ];
    }

    /**
     * @param Collection<int, Account> $accounts
     * @param list<int> $accountIds
     * @return array<string, mixed>
     */
    private function cashSeries(
        Collection $accounts,
        array $accountIds,
        int $tenantId,
        int $branchId,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $dates = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }

        if ($accountIds === []) {
            return ['dates' => $dates, 'series' => []];
        }

        $openingRows = JournalLine::query()
            ->selectRaw('journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_lines.account_id', $accountIds)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '<', $start->toDateString())
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->account_id);

        $movementRows = JournalLine::query()
            ->selectRaw('journal_lines.account_id')
            ->selectRaw('journal_entries.entry_date')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_lines.account_id', $accountIds)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '>=', $start->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $end->toDateString())
            ->groupBy('journal_lines.account_id', 'journal_entries.entry_date')
            ->orderBy('journal_entries.entry_date')
            ->get();

        $movementMap = [];
        foreach ($movementRows as $row) {
            $movementMap[(int) $row->account_id][CarbonImmutable::parse($row->entry_date)->toDateString()] = [
                'debit' => $this->balances->money((string) $row->debit),
                'credit' => $this->balances->money((string) $row->credit),
            ];
        }

        $series = [];
        foreach ($accounts as $account) {
            $opening = $openingRows->get((int) $account->id);
            $running = $opening
                ? $this->balances->signedEffect(
                    $account,
                    (string) $opening->debit,
                    (string) $opening->credit,
                )
                : '0.0000';

            $values = [];
            foreach ($dates as $day) {
                $movement = $movementMap[(int) $account->id][$day] ?? null;
                if ($movement) {
                    $running = bcadd(
                        $running,
                        $this->balances->signedEffect(
                            $account,
                            (string) $movement['debit'],
                            (string) $movement['credit'],
                        ),
                        4,
                    );
                }

                $values[] = $this->balances->money($running);
            }

            $series[] = [
                'account' => [
                    'ulid' => $account->ulid,
                    'code' => $account->code,
                    'name' => $account->name,
                ],
                'values' => $values,
            ];
        }

        return [
            'dates' => $dates,
            'series' => $series,
        ];
    }

    /**
     * @return array{
     *   debit_accounts: list<array<string, mixed>>,
     *   credit_accounts: list<array<string, mixed>>,
     *   totals: array{debit: string, credit: string}
     * }
     */
    private function accountBalances(int $tenantId, int $branchId, string $date): array
    {
        $accounts = Account::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('name')
            ->get()
            ->keyBy(fn (Account $account) => (int) $account->id);

        if ($accounts->isEmpty()) {
            return [
                'debit_accounts' => [],
                'credit_accounts' => [],
                'totals' => ['debit' => '0.0000', 'credit' => '0.0000'],
            ];
        }

        $rows = JournalLine::query()
            ->selectRaw('journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->whereIn('journal_lines.account_id', $accounts->keys()->all())
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '<=', $date)
            ->groupBy('journal_lines.account_id')
            ->get();

        $debitAccounts = [];
        $creditAccounts = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($rows as $row) {
            $account = $accounts->get((int) $row->account_id);
            if (! $account) {
                continue;
            }

            $debit = $this->balances->money((string) $row->debit);
            $credit = $this->balances->money((string) $row->credit);
            $net = bcsub($debit, $credit, 4);

            if (bccomp($net, '0.0000', 4) > 0) {
                $amount = $this->balances->money($net);
                $debitAccounts[] = [
                    'account' => [
                        'ulid' => $account->ulid,
                        'code' => $account->code,
                        'name' => $account->name,
                    ],
                    'amount' => $amount,
                ];
                $totalDebit = bcadd($totalDebit, $amount, 4);
            } elseif (bccomp($net, '0.0000', 4) < 0) {
                $amount = $this->balances->money(bcmul($net, '-1', 4));
                $creditAccounts[] = [
                    'account' => [
                        'ulid' => $account->ulid,
                        'code' => $account->code,
                        'name' => $account->name,
                    ],
                    'amount' => $amount,
                ];
                $totalCredit = bcadd($totalCredit, $amount, 4);
            }
        }

        usort($debitAccounts, fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 4));
        usort($creditAccounts, fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 4));

        return [
            'debit_accounts' => array_values($debitAccounts),
            'credit_accounts' => array_values($creditAccounts),
            'totals' => [
                'debit' => $this->balances->money($totalDebit),
                'credit' => $this->balances->money($totalCredit),
            ],
        ];
    }
}
