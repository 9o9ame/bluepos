<?php

namespace App\Accounting;

use App\Enums\CashierShiftStatus;
use App\Enums\SaleStatus;
use App\Models\CashierShift;
use App\Models\SaleItem;
use Illuminate\Support\Collection;

class DayCloseOverviewBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        int $tenantId,
        int $branchId,
        int $membershipId,
        string $date,
    ): array {
        $categoryRows = SaleItem::query()
            ->selectRaw('categories.ulid as category_ulid')
            ->selectRaw("COALESCE(categories.code, 'UNCATEGORIZED') as category_code")
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category_name")
            ->selectRaw('COUNT(DISTINCT sales.id) as orders')
            ->selectRaw('COALESCE(SUM(sale_items.line_total), 0) as amount')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', function ($join): void {
                $join->on('categories.id', '=', 'products.category_id')
                    ->on('categories.tenant_id', '=', 'sale_items.tenant_id');
            })
            ->where('sale_items.tenant_id', $tenantId)
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', SaleStatus::Posted->value)
            ->whereDate('sales.sale_date', $date)
            ->groupBy('categories.ulid', 'categories.code', 'categories.name')
            ->orderByRaw("COALESCE(categories.name, 'Uncategorized')")
            ->get();

        $categorySales = $categoryRows
            ->map(fn ($row): array => [
                'category' => [
                    'ulid' => $row->category_ulid ? (string) $row->category_ulid : null,
                    'code' => (string) $row->category_code,
                    'name' => (string) $row->category_name,
                ],
                'orders' => (int) $row->orders,
                'amount' => $this->money((string) $row->amount),
            ])
            ->values();

        $currentShift = CashierShift::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('membership_id', $membershipId)
            ->where('status', CashierShiftStatus::Open->value)
            ->with('membership.user')
            ->latest('opened_at')
            ->first();

        $branchOpenShifts = CashierShift::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', CashierShiftStatus::Open->value)
            ->with('membership.user')
            ->orderBy('opened_at')
            ->get();

        return [
            'date' => $date,
            'category_sales' => $categorySales->all(),
            'category_totals' => [
                'orders' => $categorySales->sum('orders'),
                'amount' => $this->money($categorySales->reduce(
                    fn (string $total, array $row): string => bcadd($total, $row['amount'], 4),
                    '0.0000',
                )),
            ],
            'current_shift' => $currentShift ? $this->shiftSnapshot($currentShift) : null,
            'open_shift_positions' => $branchOpenShifts
                ->map(fn (CashierShift $shift): array => $this->shiftSnapshot($shift))
                ->values()
                ->all(),
            'close_action_available' => false,
            'close_action_reason' => 'Cashier shift persistence exists, but BluePOS has no implemented open/close shift workflow yet.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftSnapshot(CashierShift $shift): array
    {
        return [
            'ulid' => $shift->ulid,
            'operator' => [
                'membership_ulid' => $shift->membership?->ulid,
                'name' => $shift->membership?->user?->name
                    ?: $shift->membership?->username
                    ?: 'Unknown Operator',
            ],
            'opened_at' => $shift->opened_at?->toISOString(),
            'opening_cash' => $this->money((string) $shift->opening_cash),
            'expected_cash' => $shift->expected_cash === null
                ? null
                : $this->money((string) $shift->expected_cash),
            'actual_cash' => $shift->actual_cash === null
                ? null
                : $this->money((string) $shift->actual_cash),
            'difference' => $shift->difference === null
                ? null
                : $this->money((string) $shift->difference),
        ];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 4);
    }
}
