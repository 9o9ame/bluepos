<?php

namespace App\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TrialBalanceReportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        int $tenantId,
        int $branchId,
        string $asOfDate,
        string $groupBy = 'level_wise',
        string $arrangeBy = 'code_asc',
    ): array {
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $asOfDate)->endOfDay();

        $balances = JournalLine::query()
            ->selectRaw(
                'journal_lines.account_id,
                 COALESCE(SUM(journal_lines.debit), 0) AS debit_total,
                 COALESCE(SUM(journal_lines.credit), 0) AS credit_total'
            )
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()
            ->forTenant($tenantId)
            ->with(['accountType.subHead.mainHead'])
            ->orderBy('code')
            ->orderBy('name')
            ->get();

        $rows = $accounts->map(function (Account $account) use ($balances): array {
            $balanceRow = $balances->get($account->id);
            $debitTotal = bcadd((string) ($balanceRow?->getAttribute('debit_total') ?? '0'), '0', 4);
            $creditTotal = bcadd((string) ($balanceRow?->getAttribute('credit_total') ?? '0'), '0', 4);
            $net = bcsub($debitTotal, $creditTotal, 4);
            $debit = bccomp($net, '0', 4) === 1 ? $net : '0.0000';
            $credit = bccomp($net, '0', 4) === -1 ? bcmul($net, '-1', 4) : '0.0000';

            $type = $account->accountType;
            $subHead = $type?->subHead;
            $mainHead = $subHead?->mainHead;

            return [
                'account' => [
                    'ulid' => $account->ulid,
                    'code' => $account->code,
                    'name' => $account->name,
                ],
                'main_head' => $mainHead ? [
                    'ulid' => $mainHead->ulid,
                    'name' => $mainHead->name,
                    'sort_order' => (int) $mainHead->sort_order,
                ] : null,
                'account_type_head' => $subHead ? [
                    'ulid' => $subHead->ulid,
                    'name' => $subHead->name,
                    'sort_order' => (int) $subHead->sort_order,
                ] : null,
                'account_type' => $type ? [
                    'ulid' => $type->ulid,
                    'code' => $type->code,
                    'name' => $type->name,
                    'sort_order' => (int) $type->sort_order,
                ] : null,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $net,
            ];
        })->values();

        $groups = $this->groupRows($rows, $groupBy, $arrangeBy);
        $totals = $this->totals($rows);

        return [
            'as_of_date' => $asOfDate,
            'group_by' => $groupBy,
            'arrange_by' => $arrangeBy,
            'groups' => $groups,
            'totals' => $totals,
        ];
    }

    /**
     * Six-column period report. All figures come from posted journal entries
     * in the server-resolved tenant and branch context.
     *
     * @return array<string, mixed>
     */
    public function buildSixColumns(
        int $tenantId,
        int $branchId,
        string $startDate,
        string $endDate,
        string $groupBy = 'level_wise',
        string $arrangeBy = 'code_asc',
        array $mainHeadUlids = [],
        array $subHeadUlids = [],
        array $accountTypeUlids = [],
    ): array {
        $closing = $this->build($tenantId, $branchId, $endDate, $groupBy, $arrangeBy);

        $periods = JournalLine::query()
            ->selectRaw("journal_lines.account_id,
                COALESCE(SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_lines.debit ELSE 0 END), 0) AS previous_debit,
                COALESCE(SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_lines.credit ELSE 0 END), 0) AS previous_credit,
                COALESCE(SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_lines.debit ELSE 0 END), 0) AS within_debit,
                COALESCE(SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_lines.credit ELSE 0 END), 0) AS within_credit",
                [$startDate, $startDate, $startDate, $startDate])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.branch_id', $branchId)
            ->where('journal_entries.status', JournalStatus::Posted->value)
            ->where('journal_entries.entry_date', '<=', $endDate)
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        // Filtering applies only to hierarchy membership, never to posted ledger amounts.
        // Rebuild group subtotals after filtering to avoid unfiltered aggregates.
        $selectedRows = collect($closing['groups'])->flatMap(fn (array $group) => $group['rows'])
            ->filter(function (array $row) use ($mainHeadUlids, $subHeadUlids, $accountTypeUlids): bool {
                return (empty($mainHeadUlids) || in_array($row['main_head']['ulid'] ?? null, $mainHeadUlids, true))
                    && (empty($subHeadUlids) || in_array($row['account_type_head']['ulid'] ?? null, $subHeadUlids, true))
                    && (empty($accountTypeUlids) || in_array($row['account_type']['ulid'] ?? null, $accountTypeUlids, true));
            })->values();
        $closing['groups'] = $this->groupRows($selectedRows, $groupBy, $arrangeBy);

        $accountIds = Account::query()->forTenant($tenantId)->pluck('id', 'ulid');
        $zero = '0.0000';
        $totals = array_fill_keys([
            'previous_debit', 'previous_credit', 'within_debit',
            'within_credit', 'closing_debit', 'closing_credit',
        ], $zero);

        foreach ($closing['groups'] as &$group) {
            foreach ($group['rows'] as &$row) {
                $accountId = $accountIds->get($row['account']['ulid']);
                $source = $accountId ? $periods->get($accountId) : null;
                $previousNet = bcsub(
                    (string) ($source?->getAttribute('previous_debit') ?? $zero),
                    (string) ($source?->getAttribute('previous_credit') ?? $zero),
                    4
                );
                $row['six_columns'] = [
                    'previous_debit' => bccomp($previousNet, $zero, 4) > 0 ? $previousNet : $zero,
                    'previous_credit' => bccomp($previousNet, $zero, 4) < 0 ? bcmul($previousNet, '-1', 4) : $zero,
                    'within_debit' => bcadd((string) ($source?->getAttribute('within_debit') ?? $zero), $zero, 4),
                    'within_credit' => bcadd((string) ($source?->getAttribute('within_credit') ?? $zero), $zero, 4),
                    'closing_debit' => $row['debit'],
                    'closing_credit' => $row['credit'],
                ];
                foreach ($totals as $key => &$total) {
                    $total = bcadd($total, $row['six_columns'][$key], 4);
                }
                unset($total);
            }
            unset($row);
        }
        unset($group);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'group_by' => $groupBy,
            'arrange_by' => $arrangeBy,
            'groups' => $closing['groups'],
            'totals' => $totals,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function groupRows(Collection $rows, string $groupBy, string $arrangeBy): array
    {
        $grouped = $rows->groupBy(fn (array $row): string => $this->groupKey($row, $groupBy));

        $groups = $grouped->map(function (Collection $groupRows, string $key) use ($groupBy, $arrangeBy): array {
            if ($groupBy === 'level_wise') {
                $sections = $this->levelWiseSections($groupRows->values(), $arrangeBy);
                $sorted = collect($sections)
                    ->flatMap(fn (array $section) => collect($section['account_types'])
                        ->flatMap(fn (array $type) => $type['rows']))
                    ->values();
            } else {
                $sections = null;
                $sorted = $this->sortRows($groupRows->values(), $arrangeBy);
            }

            $first = $sorted->first();

            return [
                'key' => $key,
                'label' => $this->groupLabel($first, $groupBy),
                'rows' => $sorted->values()->all(),
                'sections' => $sections,
                'totals' => $this->totals($sorted),
            ];
        })->values();

        if ($groupBy === 'level_wise') {
            $groups = $groups->sortBy(function (array $group): array {
                $first = $group['rows'][0] ?? [];

                return [
                    $first['main_head']['sort_order'] ?? 9999,
                    $group['label'],
                ];
            })->values();
        } else {
            $groups = $groups->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();
        }

        return $groups->all();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function groupKey(array $row, string $groupBy): string
    {
        return match ($groupBy) {
            'account_type' => (string) ($row['account_type']['ulid'] ?? 'ungrouped'),
            'account_type_head' => (string) ($row['account_type_head']['ulid'] ?? 'ungrouped'),
            'main_account', 'level_wise' => (string) ($row['main_head']['ulid'] ?? 'ungrouped'),
            default => 'ungrouped',
        };
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function groupLabel(?array $row, string $groupBy): string
    {
        if (! $row) {
            return 'Ungrouped';
        }

        return match ($groupBy) {
            'account_type' => trim((string) ($row['account_type']['code'] ?? '').' '.(string) ($row['account_type']['name'] ?? '')) ?: 'Ungrouped',
            'account_type_head' => (string) ($row['account_type_head']['name'] ?? 'Ungrouped'),
            'main_account', 'level_wise' => (string) ($row['main_head']['name'] ?? 'Ungrouped'),
            default => 'All Accounts',
        };
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function levelWiseSections(Collection $rows, string $arrangeBy): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row['account_type_head']['ulid'] ?? 'ungrouped'))
            ->map(function (Collection $sectionRows) use ($arrangeBy): array {
                $firstSectionRow = $sectionRows->first();

                $types = $sectionRows
                    ->groupBy(fn (array $row): string => (string) ($row['account_type']['ulid'] ?? 'ungrouped'))
                    ->map(function (Collection $typeRows) use ($arrangeBy): array {
                        $sorted = $this->sortRows($typeRows->values(), $arrangeBy);
                        $firstTypeRow = $sorted->first();

                        return [
                            'account_type' => $firstTypeRow['account_type'] ?? null,
                            'rows' => $sorted->values()->all(),
                            'totals' => $this->totals($sorted),
                        ];
                    })
                    ->sortBy(function (array $type): array {
                        return [
                            $type['account_type']['sort_order'] ?? 9999,
                            $type['account_type']['name'] ?? '',
                        ];
                    })
                    ->values();

                return [
                    'account_type_head' => $firstSectionRow['account_type_head'] ?? null,
                    'account_types' => $types->all(),
                    'totals' => $this->totals($sectionRows),
                ];
            })
            ->sortBy(function (array $section): array {
                return [
                    $section['account_type_head']['sort_order'] ?? 9999,
                    $section['account_type_head']['name'] ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $arrangeBy): Collection
    {
        $rows = $rows->all();

        usort($rows, function (array $left, array $right) use ($arrangeBy): int {
            [$field, $direction] = str_ends_with($arrangeBy, '_desc')
                ? [substr($arrangeBy, 0, -5), 'desc']
                : [substr($arrangeBy, 0, -4), 'asc'];

            $comparison = match ($field) {
                'balance' => bccomp((string) $left['balance'], (string) $right['balance'], 4),
                'debit' => bccomp((string) $left['debit'], (string) $right['debit'], 4),
                'credit' => bccomp((string) $left['credit'], (string) $right['credit'], 4),
                'name' => strnatcasecmp((string) $left['account']['name'], (string) $right['account']['name']),
                default => strnatcasecmp((string) $left['account']['code'], (string) $right['account']['code']),
            };

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return collect($rows);
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array{debit:string,credit:string,difference:string,difference_side:string}
     */
    private function totals(Collection $rows): array
    {
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($rows as $row) {
            $debit = bcadd($debit, (string) $row['debit'], 4);
            $credit = bcadd($credit, (string) $row['credit'], 4);
        }

        $difference = bcsub($debit, $credit, 4);
        $side = bccomp($difference, '0', 4) >= 0 ? 'Dr' : 'Cr';

        return [
            'debit' => $debit,
            'credit' => $credit,
            'difference' => bccomp($difference, '0', 4) < 0
                ? bcmul($difference, '-1', 4)
                : $difference,
            'difference_side' => $side,
        ];
    }
}
