<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopSpecialReportController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    private function checkAccess(): void
    {
        if (! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to view reports.', 403);
        }
    }

    public function options(TenantContext $context): JsonResponse
    {
        $this->checkAccess();
        $tenant = $context->tenantId();
        $names = fn ($query) => $query->orderBy('name')->get(['ulid', 'name'])
            ->map(fn ($item) => ['ulid' => $item->ulid, 'name' => $item->name])->values();

        return response()->json([
            'categories' => $names(Category::query()->forTenant($tenant)),
            'brands' => $names(Brand::query()->forTenant($tenant)),
            'customers' => $names(Customer::query()->forTenant($tenant)),
            'suppliers' => $names(Supplier::query()->forTenant($tenant)),
            'salespeople' => $names(PartyProfile::query()->forTenant($tenant)),
            'areas' => Customer::query()->forTenant($tenant)
                ->whereNotNull('area')->where('area', '<>', '')
                ->distinct()->orderBy('area')->pluck('area'),
        ]);
    }

    public function report(Request $request, TenantContext $context): JsonResponse
    {
        $this->checkAccess();
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'top' => ['required', 'integer', 'min:1', 'max:100'],
            'kind' => ['required', 'in:customers,vendors,products,salespeople'],
            'purchasing' => ['sometimes', 'boolean'],
            'by_quantity' => ['sometimes', 'boolean'],
            'format' => ['sometimes', 'in:standard,format1'],
            'category_ulids' => ['sometimes', 'array', 'max:100'],
            'category_ulids.*' => ['required', 'ulid', 'distinct'],
            'brand_ulids' => ['sometimes', 'array', 'max:100'],
            'brand_ulids.*' => ['required', 'ulid', 'distinct'],
            'party_ulids' => ['sometimes', 'array', 'max:100'],
            'party_ulids.*' => ['required', 'ulid', 'distinct'],
            'area' => ['nullable', 'string', 'max:150'],
        ]);
        $tenant = $context->tenantId();
        $branch = $context->branchId();
        $purchasing = (bool) ($data['purchasing'] ?? false);
        $kind = $data['kind'];
        if ($purchasing && in_array($kind, ['customers', 'salespeople'], true)) {
            throw new ApiException('UNSUPPORTED_RANKING', 'Purchase ranking is available for vendors and products.', 422);
        }
        if (! $purchasing && $kind === 'vendors') {
            throw new ApiException('UNSUPPORTED_RANKING', 'Vendor ranking requires purchasing.', 422);
        }

        $idsFor = function (string $model, string $field) use ($data, $tenant) {
            $ulids = $data[$field] ?? [];
            if ($ulids === []) return [];
            $rows = $model::query()->forTenant($tenant)->whereIn('ulid', $ulids)->pluck('id')->all();
            if (count($rows) !== count($ulids)) {
                throw new ApiException('INVALID_FILTER', 'Selected filter is unavailable.', 404);
            }
            return $rows;
        };
        $categoryIds = $idsFor(Category::class, 'category_ulids');
        $brandIds = $idsFor(Brand::class, 'brand_ulids');
        $partyModel = $purchasing ? Supplier::class : ($kind === 'salespeople' ? PartyProfile::class : Customer::class);
        $partyIds = $idsFor($partyModel, 'party_ulids');

        $query = $purchasing
            ? DB::table('purchase_invoice_lines as li')
                ->join('purchase_invoices as doc', 'doc.id', '=', 'li.purchase_invoice_id')
                ->join('products as prod', 'prod.id', '=', 'li.product_id')
                ->leftJoin('suppliers as party', 'party.id', '=', 'doc.supplier_id')
            : DB::table('sale_items as li')
                ->join('sales as doc', 'doc.id', '=', 'li.sale_id')
                ->join('products as prod', 'prod.id', '=', 'li.product_id')
                ->leftJoin('customers as party', 'party.id', '=', 'doc.customer_id');
        $query->where('doc.tenant_id', $tenant)->where('doc.branch_id', $branch)
            ->where('doc.status', 'posted')
            ->where('li.tenant_id', $tenant)->where('prod.tenant_id', $tenant)
            ->whereBetween($purchasing ? 'doc.invoice_date' : 'doc.sale_date', [$data['from'], $data['to']])
            ->when($categoryIds !== [], fn ($q) => $q->whereIn('prod.category_id', $categoryIds))
            ->when($brandIds !== [], fn ($q) => $q->whereIn('prod.brand_id', $brandIds))
            ->when(! empty($data['area']) && ! $purchasing, fn ($q) => $q->where('party.area', $data['area']));

        if ($kind === 'salespeople') {
            $query->join('party_profiles as person', function ($join) use ($tenant) {
                $join->on('person.id', '=', 'doc.salesman_party_profile_id')->where('person.tenant_id', '=', $tenant);
            });
            $key = 'person.id';
            $name = 'person.name';
            if ($partyIds !== []) $query->whereIn($key, $partyIds);
        } elseif ($kind === 'products') {
            $key = 'prod.id';
            $name = 'prod.name';
            // Party selection narrows sales/purchases for the product ranking.
            if ($partyIds !== []) $query->whereIn('party.id', $partyIds);
        } else {
            $key = 'party.id';
            $name = 'party.name';
            $query->whereNotNull('party.id');
            if ($partyIds !== []) $query->whereIn($key, $partyIds);
        }
        $rows = $query->groupBy($key, $name)
            ->selectRaw("$name as description, SUM(li.quantity) as qty, SUM(li.line_total) as amount")
            ->orderByRaw(($data['by_quantity'] ?? false) ? 'SUM(li.quantity) DESC' : 'SUM(li.line_total) DESC')
            ->orderBy($name)->limit($data['top'])->get()
            ->map(fn ($row, $index) => [
                'rank' => $index + 1, 'description' => $row->description,
                'qty' => (string) $row->qty, 'amount' => (string) $row->amount,
            ])->values();
        return response()->json([
            'from' => $data['from'], 'to' => $data['to'], 'kind' => $kind,
            'format' => $data['format'] ?? 'standard',
            'ranking' => $rows,
        ]);
    }
}
