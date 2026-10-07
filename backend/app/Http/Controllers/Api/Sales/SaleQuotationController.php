<?php

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\CreateSaleQuotationAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleQuotationRequest;
use App\Http\Resources\Sales\SaleQuotationResource;
use App\Models\Sale;
use App\Models\SaleQuotation;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleQuotationController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): array
    {
        $this->authorize('viewAny', Sale::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = SaleQuotation::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->with(CreateSaleQuotationAction::with())
            ->orderByDesc('quotation_date')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));

            if ($term !== '') {
                $query->where(function ($quotation) use ($term): void {
                    $quotation
                        ->where('document_number', 'ilike', '%'.$term.'%')
                        ->orWhereHas('customer', function ($customer) use ($term): void {
                            $customer
                                ->where('code', 'ilike', '%'.$term.'%')
                                ->orWhere('name', 'ilike', '%'.$term.'%');
                        })
                        ->orWhereHas('salesmanParty', function ($salesman) use ($term): void {
                            $salesman
                                ->where('code', 'ilike', '%'.$term.'%')
                                ->orWhere('name', 'ilike', '%'.$term.'%');
                        });
                });
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('quotation_date', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('quotation_date', '<=', (string) $request->string('date_to'));
        }

        $page = $query->paginate($perPage);

        return [
            'data' => SaleQuotationResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function store(
        StoreSaleQuotationRequest $request,
        CreateSaleQuotationAction $action,
    ): JsonResponse {
        $this->authorize('create', Sale::class);

        $quotation = $action->execute(
            $request->validated(),
            (string) $request->header('Idempotency-Key', ''),
        );

        return (new SaleQuotationResource($quotation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        string $quotationUlid,
        TenantContext $tenantContext,
    ): SaleQuotationResource {
        $this->authorize('viewAny', Sale::class);

        $quotation = SaleQuotation::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('ulid', $quotationUlid)
            ->with(CreateSaleQuotationAction::with())
            ->first();

        if (! $quotation) {
            throw new ApiException(
                'NOT_FOUND',
                'The requested resource was not found.',
                404,
            );
        }

        return new SaleQuotationResource($quotation);
    }
}
