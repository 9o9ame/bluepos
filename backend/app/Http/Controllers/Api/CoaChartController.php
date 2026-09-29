<?php

namespace App\Http\Controllers\Api;

use App\Accounting\CoaChartBuilder;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMainHead;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoaChartController extends Controller
{
    public function __construct(private readonly CoaChartBuilder $builder) {}

    public function show(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('viewAny', AccountMainHead::class);

        $grouped = filter_var($request->query('grouped', '0'), FILTER_VALIDATE_BOOLEAN);

        $tenantId = $tenantContext->tenantId();

        if ($grouped) {
            return response()->json([
                'mode' => 'grouped',
                'grouped' => $this->builder->groupedHierarchy($tenantId),
            ]);
        }

        return response()->json([
            'mode' => 'flat',
            'flat' => $this->builder->flatLeaves($tenantId),
        ]);
    }

    public function leafAccounts(TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $rows = Account::query()
            ->forTenant($tenantContext->tenantId())
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('name')
            ->get(['ulid', 'code', 'name'])
            ->map(fn (Account $account) => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }
}
