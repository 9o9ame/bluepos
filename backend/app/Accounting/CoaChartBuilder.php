<?php

namespace App\Accounting;

use App\Models\Account;
use App\Models\AccountMainHead;
class CoaChartBuilder
{
    /**
     * @return list<array<string, mixed>>
     */
    public function flatLeaves(int $tenantId): array
    {
        $accounts = $this->leafQuery($tenantId)->get()->sortBy(
            fn (Account $account) => $this->leafSortKey($account),
            SORT_REGULAR,
        );

        return $accounts->map(fn (Account $account) => $this->flatRow($account))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupedHierarchy(int $tenantId): array
    {
        $accountsByType = $this->leafQuery($tenantId)
            ->get()
            ->groupBy('account_type_id');

        $mains = AccountMainHead::query()
            ->forTenant($tenantId)
            ->with([
                'subHeads' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')
                    ->with(['accountTypes' => fn ($aq) => $aq->orderBy('sort_order')->orderBy('name')]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($mains as $main) {
            $mainNode = [
                'ulid' => $main->ulid,
                'label' => $this->headLabel($main->sort_order, $main->name, 2),
                'heads' => [],
            ];

            foreach ($main->subHeads as $sub) {
                $headNode = [
                    'ulid' => $sub->ulid,
                    'label' => $this->headLabel($sub->sort_order, $sub->name, 3),
                    'sub_heads' => [],
                ];

                foreach ($sub->accountTypes as $type) {
                    $leaves = ($accountsByType->get($type->id) ?? collect())
                        ->sortBy(fn (Account $a) => [$a->code, $a->name])
                        ->values()
                        ->map(fn (Account $account) => [
                            'ulid' => $account->ulid,
                            'label' => $this->accountLabel($account),
                            'leaf_source' => $this->leafSource($account),
                        ])
                        ->all();

                    $headNode['sub_heads'][] = [
                        'ulid' => $type->ulid,
                        'label' => $this->headLabel($type->sort_order, $type->name, 4),
                        'accounts' => $leaves,
                    ];
                }

                $mainNode['heads'][] = $headNode;
            }

            $result[] = $mainNode;
        }

        return $result;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Account>
     */
    private function leafQuery(int $tenantId)
    {
        return Account::query()
            ->forTenant($tenantId)
            ->with(['accountType.subHead.mainHead'])
            ->orderBy('code')
            ->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function flatRow(Account $account): array
    {
        $type = $account->accountType;
        $sub = $type?->subHead;
        $main = $sub?->mainHead;

        return [
            'account_ulid' => $account->ulid,
            'main_head_label' => $main ? $this->headLabel($main->sort_order, $main->name, 2) : '—',
            'account_label' => $this->accountLabel($account),
            'head_label' => $sub ? $this->headLabel($sub->sort_order, $sub->name, 3) : '—',
            'sub_head_label' => $type ? $this->headLabel($type->sort_order, $type->name, 4) : '—',
            'leaf_source' => $this->leafSource($account),
        ];
    }

    private function headLabel(int $sortOrder, string $name, int $pad): string
    {
        return str_pad((string) max(0, $sortOrder), $pad, '0', STR_PAD_LEFT).'-'.$name;
    }

    private function accountLabel(Account $account): string
    {
        return trim($account->code).'-'.$account->name;
    }

    private function leafSource(Account $account): string
    {
        if ($account->supplier_id) {
            return 'supplier';
        }
        if ($account->customer_id) {
            return 'customer';
        }

        return 'manual';
    }

    /**
     * @return array<int, int|string>
     */
    private function leafSortKey(Account $account): array
    {
        $type = $account->accountType;
        $sub = $type?->subHead;
        $main = $sub?->mainHead;

        return [
            $main?->sort_order ?? 9999,
            $main?->name ?? '',
            $sub?->sort_order ?? 9999,
            $sub?->name ?? '',
            $type?->sort_order ?? 9999,
            $type?->name ?? '',
            $account->code,
            $account->name,
        ];
    }
}
