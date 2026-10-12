<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\RecalculateStockBalancesAction;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class RecalculateStockController extends Controller
{
    public function __invoke(
        PermissionService $permissions,
        TenantContext $tenantContext,
        RecalculateStockBalancesAction $recalculate,
    ): JsonResponse {
        if (! $permissions->can('inventory.adjust')) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to recalculate stock balances.', 403);
        }

        return response()->json([
            'warehouse' => [
                'ulid' => $tenantContext->warehouse()->ulid,
                'code' => $tenantContext->warehouse()->code,
                'name' => $tenantContext->warehouse()->name,
            ],
            ...$recalculate->execute(),
        ]);
    }
}
