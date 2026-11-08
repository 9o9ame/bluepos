<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Posted-sales activity only; draft and cancelled invoices never count. */
final class InactiveCustomersReportController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function __invoke(Request $request, TenantContext $context): array
    {
        if (! $this->permissions->can('reports.accounting')
            || (! $this->permissions->can('customers.view') && ! $this->permissions->can('customers.manage'))) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view customer activity reports.', 403);
        }

        $input = $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d'],
            'min_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'max_days' => ['required', 'integer', 'gte:min_days', 'max:3650'],
            'include_never' => ['sometimes', 'boolean'],
            'having_balance_only' => ['sometimes', 'boolean'],
            'area' => ['sometimes', 'string', 'max:120'],
            'q' => ['sometimes', 'string', 'max:180'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $input['as_of'])->startOfDay();
        $fromDate = $asOf->subDays((int) $input['max_days'])->toDateString();
        $toDate = $asOf->subDays((int) $input['min_days'])->toDateString();

        $lastSale = DB::table('sales')
            ->where('tenant_id', $context->tenantId())
            ->where('branch_id', $context->branchId())
            ->where('status', SaleStatus::Posted->value)
            ->where('sale_date', '<=', $input['as_of'])
            ->selectRaw('customer_id, MAX(sale_date) AS last_sale_date')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        $query = Customer::query()
            ->forTenant($context->tenantId())
            ->leftJoinSub($lastSale, 'last_sales', fn ($join) => $join->on('last_sales.customer_id', '=', 'customers.id'))
            ->select([
                'customers.ulid', 'customers.code', 'customers.name', 'customers.area',
                'customers.address', 'customers.mobile', 'customers.phone',
                'last_sales.last_sale_date',
            ])
            ->where(function ($customers) use ($input, $fromDate, $toDate): void {
                $customers->whereBetween('last_sales.last_sale_date', [$fromDate, $toDate]);
                if (! empty($input['include_never'])) {
                    $customers->orWhereNull('last_sales.last_sale_date');
                }
            });

        if (! empty($input['having_balance_only'])) {
            $query->whereExists(function ($sub) use ($context, $input): void {
                $sub->selectRaw('1')
                    ->from('accounts as inactive_accounts')
                    ->join('journal_lines as inactive_lines', 'inactive_lines.account_id', '=', 'inactive_accounts.id')
                    ->join('journal_entries as inactive_journals', 'inactive_journals.id', '=', 'inactive_lines.journal_entry_id')
                    ->whereColumn('inactive_accounts.customer_id', 'customers.id')
                    ->where('inactive_accounts.tenant_id', $context->tenantId())
                    ->where('inactive_lines.tenant_id', $context->tenantId())
                    ->where('inactive_journals.tenant_id', $context->tenantId())
                    ->where('inactive_journals.branch_id', $context->branchId())
                    ->where('inactive_journals.status', 'posted')
                    ->where('inactive_journals.entry_date', '<=', $input['as_of'])
                    ->groupBy('inactive_accounts.customer_id')
                    ->havingRaw('COALESCE(SUM(inactive_lines.debit - inactive_lines.credit), 0) <> 0');
            });
        }

        if (isset($input['area']) && trim($input['area']) !== '') {
            $query->whereRaw('LOWER(BTRIM(customers.area)) = LOWER(?)', [trim($input['area'])]);
        }

        if (isset($input['q']) && trim($input['q']) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($input['q'])).'%';
            $query->where(fn ($q) => $q->where('customers.name', 'ilike', $term)
                ->orWhere('customers.code', 'ilike', $term)
                ->orWhere('customers.area', 'ilike', $term));
        }

        $page = $query->orderBy('customers.name')->orderBy('customers.id')
            ->paginate((int) ($input['per_page'] ?? 100));

        return ['data' => collect($page->items())->map(function (Customer $customer) use ($asOf): array {
            $lastSale = $customer->getAttribute('last_sale_date');
            return [
                'ulid' => $customer->ulid,
                'code' => $customer->code,
                'name' => $customer->name,
                'area' => $customer->area,
                'address' => $customer->address,
                'mobile' => $customer->mobile,
                'phone' => $customer->phone,
                'last_sale_date' => $lastSale,
                'days_inactive' => $lastSale === null ? null
                    : (int) CarbonImmutable::parse($lastSale)->startOfDay()->diffInDays($asOf),
            ];
        })->values()->all(),
        'meta' => [
            'as_of' => $input['as_of'],
            'min_days' => (int) $input['min_days'],
            'max_days' => (int) $input['max_days'],
            'include_never' => ! empty($input['include_never']),
            'having_balance_only' => ! empty($input['having_balance_only']),
            'area' => isset($input['area']) ? trim($input['area']) : null,
            'branch_ulid' => $context->branch()->ulid,
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
            'definition' => 'Days since last posted customer sale in the selected branch. Never-sold customers are optional.',
        ]];
    }
}
