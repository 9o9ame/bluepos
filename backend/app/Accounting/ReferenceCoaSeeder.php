<?php

namespace App\Accounting;

use App\Models\Account;
use App\Models\AccountMainHead;
use App\Models\AccountSubHead;
use App\Models\AccountType;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class ReferenceCoaSeeder
{
    /**
     * @return array{main_heads: int, sub_heads: int, account_types: int, accounts: int}
     */
    public function seed(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant): array {
            $tenantId = (int) $tenant->id;
            $accountsBefore = Account::query()->forTenant($tenantId)->count();

            $mains = 0;
            $subs = 0;
            $types = 0;

            $mainByKey = [];
            foreach ($this->mainHeads() as $row) {
                $main = AccountMainHead::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'name' => $row['name'],
                    ],
                    [
                        'sort_order' => $row['sort_order'],
                        'is_active' => true,
                    ],
                );
                $mainByKey[$row['key']] = $main;
                $mains++;
            }

            $subByKey = [];
            foreach ($this->subHeads() as $row) {
                $main = $mainByKey[$row['main']];
                $sub = AccountSubHead::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'main_head_id' => $main->id,
                        'name' => $row['name'],
                    ],
                    [
                        'sort_order' => $row['sort_order'],
                        'is_active' => true,
                    ],
                );
                $subByKey[$row['key']] = $sub;
                $subs++;
            }

            foreach ($this->accountTypes() as $row) {
                $sub = $subByKey[$row['sub']];
                $attrs = [
                    'sub_head_id' => $sub->id,
                    'name' => $row['name'],
                    'is_active' => true,
                ];

                if (array_key_exists('is_bank', $row)) {
                    $attrs['is_bank'] = $row['is_bank'];
                }
                if (array_key_exists('is_receivable', $row)) {
                    $attrs['is_receivable'] = $row['is_receivable'];
                }
                if (array_key_exists('is_payable', $row)) {
                    $attrs['is_payable'] = $row['is_payable'];
                }
                if (array_key_exists('pnl_grouping_label', $row)) {
                    $attrs['pnl_grouping_label'] = $row['pnl_grouping_label'];
                }
                if (array_key_exists('sort_order', $row)) {
                    $attrs['sort_order'] = $row['sort_order'];
                }

                $existing = AccountType::query()
                    ->forTenant($tenantId)
                    ->where('code', $row['code'])
                    ->first();

                if ($existing) {
                    $existing->fill($attrs);
                    $existing->save();
                } else {
                    AccountType::query()->create([
                        'tenant_id' => $tenantId,
                        'code' => $row['code'],
                        'is_cash' => false,
                        'is_bank' => false,
                        'is_receivable' => false,
                        'is_payable' => false,
                        'sort_order' => 0,
                        ...$attrs,
                    ]);
                }
                $types++;
            }

            $accountsAfter = Account::query()->forTenant($tenantId)->count();

            return [
                'main_heads' => $mains,
                'sub_heads' => $subs,
                'account_types' => $types,
                'accounts' => $accountsAfter - $accountsBefore,
            ];
        });
    }

    /**
     * @return list<array{key: string, name: string, sort_order: int}>
     */
    private function mainHeads(): array
    {
        return [
            ['key' => 'assets', 'name' => 'ASSETS', 'sort_order' => 1],
            ['key' => 'liabilities', 'name' => 'LIABILITIES', 'sort_order' => 2],
            ['key' => 'expenses', 'name' => 'EXPENSES', 'sort_order' => 3],
            ['key' => 'revenues', 'name' => 'REVENUES', 'sort_order' => 4],
            ['key' => 'capital', 'name' => 'CAPITAL', 'sort_order' => 5],
            ['key' => 'inventory', 'name' => 'INVENTORY', 'sort_order' => 6],
        ];
    }

    /**
     * @return list<array{key: string, name: string, main: string, sort_order: int}>
     */
    private function subHeads(): array
    {
        return [
            ['key' => 'current_assets', 'name' => 'CURRENT ASSETS', 'main' => 'assets', 'sort_order' => 10],
            ['key' => 'non_current_assets', 'name' => 'NON CURRENT ASSETS', 'main' => 'assets', 'sort_order' => 11],
            ['key' => 'fixed_assets', 'name' => 'FIXED ASSETS', 'main' => 'assets', 'sort_order' => 12],
            ['key' => 'short_term', 'name' => 'SHORT TERM LIABILITIES', 'main' => 'liabilities', 'sort_order' => 20],
            ['key' => 'long_term', 'name' => 'LONG TERM LIABILITIES', 'main' => 'liabilities', 'sort_order' => 21],
            ['key' => 'expenses', 'name' => 'EXPENSES', 'main' => 'expenses', 'sort_order' => 30],
            ['key' => 'revenues', 'name' => 'REVENUES', 'main' => 'revenues', 'sort_order' => 40],
            ['key' => 'capital', 'name' => 'CAPITAL', 'main' => 'capital', 'sort_order' => 50],
            ['key' => 'inventory', 'name' => 'INVENTORY', 'main' => 'inventory', 'sort_order' => 60],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountTypes(): array
    {
        return [
            ['code' => '0010', 'name' => 'CASH', 'sub' => 'current_assets'],
            ['code' => '0011', 'name' => 'ACCOUNT RECEIVABLE', 'sub' => 'current_assets', 'is_receivable' => true],
            ['code' => '0012', 'name' => 'CASH IN BANK', 'sub' => 'current_assets', 'is_bank' => true],
            ['code' => '0013', 'name' => 'OTHER ASSETS', 'sub' => 'current_assets'],
            ['code' => '0014', 'name' => 'FIXED ASSETS', 'sub' => 'fixed_assets'],
            ['code' => '0015', 'name' => 'EMPLOYEES', 'sub' => 'expenses'],

            ['code' => '0020', 'name' => 'ACCOUNT PAYABLE', 'sub' => 'short_term', 'is_payable' => true],
            ['code' => '0021', 'name' => 'CREDITORS', 'sub' => 'short_term'],
            ['code' => '0022', 'name' => 'LOAN', 'sub' => 'long_term'],
            ['code' => '0023', 'name' => 'LIABILITIES', 'sub' => 'short_term'],

            ['code' => '0030', 'name' => 'EXPENSES', 'sub' => 'expenses', 'pnl_grouping_label' => 'OTHER OPERATIVE EXPENSES', 'sort_order' => 4],
            ['code' => '0031', 'name' => 'INCOME TAX', 'sub' => 'expenses', 'pnl_grouping_label' => 'OTHER OPERATIVE EXPENSES', 'sort_order' => 4],
            ['code' => '0032', 'name' => 'DIRECT EXPENSES', 'sub' => 'expenses', 'pnl_grouping_label' => 'MANUFACTURING EXPENSES', 'sort_order' => 3],

            ['code' => '0040', 'name' => 'REVENUES', 'sub' => 'revenues', 'pnl_grouping_label' => 'SALES REVENUES', 'sort_order' => 1],
            ['code' => '0042', 'name' => 'DIRECT REVENUES', 'sub' => 'revenues', 'pnl_grouping_label' => 'DIRECT REVENUES', 'sort_order' => 4],

            ['code' => '0050', 'name' => 'CAPITAL / OWNERS EQUITY', 'sub' => 'capital'],

            ['code' => '0060', 'name' => 'INVENTORY', 'sub' => 'inventory', 'pnl_grouping_label' => 'COST OF GOOD SOLD', 'sort_order' => 2],
            ['code' => '0061', 'name' => 'INCOME / LOSS', 'sub' => 'capital'],
            ['code' => '0062', 'name' => 'ENDING STOCK', 'sub' => 'capital'],
            ['code' => '0063', 'name' => 'ADMIN EXP', 'sub' => 'expenses'],
            ['code' => '0064', 'name' => 'ADVANCE / DEPOSIT', 'sub' => 'current_assets'],
            ['code' => '0065', 'name' => 'COST OF GOODS SOLD', 'sub' => 'inventory'],
            ['code' => '0066', 'name' => 'MANUFACTURE EXPENSES', 'sub' => 'expenses'],
            ['code' => '0067', 'name' => 'SUSPENSE', 'sub' => 'current_assets'],
            ['code' => '0068', 'name' => 'UNKNOWN', 'sub' => 'current_assets'],
        ];
    }
}
