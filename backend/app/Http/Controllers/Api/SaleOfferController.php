<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaleSchemes\EvaluateSaleOffersAction;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaleSchemes\EvaluateSaleOffersRequest;
use Illuminate\Http\JsonResponse;

class SaleOfferController extends Controller
{
    public function evaluate(
        EvaluateSaleOffersRequest $request,
        EvaluateSaleOffersAction $evaluate,
        PermissionService $permissions,
    ): JsonResponse {
        $canEvaluate = $permissions->can('sales.create')
            || $permissions->can('sales.give_free_packaging')
            || $permissions->can('sales.apply_scheme')
            || $permissions->can('sale_schemes.view')
            || $permissions->can('sale_schemes.manage');

        if (! $canEvaluate) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to evaluate sale offers.', 403);
        }

        $result = $evaluate->execute(
            (string) $request->validated('subtotal'),
            $request->validated('document_date'),
        );

        return response()->json($result);
    }
}
