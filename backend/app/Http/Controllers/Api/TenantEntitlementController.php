<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantEntitlementController extends Controller
{
    public function show(TenantContext $tenantContext, TenantEntitlementService $entitlements): JsonResponse
    {
        $tenant = $tenantContext->tenant();
        $snapshot = $entitlements->snapshot($tenant);
        $subscription = $entitlements->subscription($tenant);

        return response()->json([
            'plan' => $subscription?->plan ? [
                'code' => $subscription->plan->code,
                'name' => $subscription->plan->name,
                'status' => $subscription->status->value,
            ] : null,
            'features' => $snapshot['features'],
            'limits' => $snapshot['limits'],
            'usage' => $snapshot['usage'],
        ]);
    }
}
