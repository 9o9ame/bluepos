<?php

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\CollectSalePaymentAction;
use App\Actions\Sales\CreateSaleAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalePaymentRequest;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Resources\Sales\SalePaymentResource;
use App\Http\Resources\Sales\SaleResource;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Sale::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = Sale::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['customer', 'branch', 'warehouse'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id');

        if ($request->filled('customer_ulid')) {
            $query->whereHas('customer', fn ($q) => $q->where('ulid', (string) $request->string('customer_ulid')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('sale_date', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('sale_date', '<=', (string) $request->string('date_to'));
        }

        $page = $query->paginate($perPage);

        return [
            'data' => SaleResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function salesmen(TenantContext $tenantContext): array
    {
        $this->authorize('create', Sale::class);

        $branchId = $tenantContext->branchId();

        return Membership::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('status', MembershipStatus::Active->value)
            ->with(['user', 'roles', 'branches'])
            ->orderBy('id')
            ->get()
            ->filter(function (Membership $membership) use ($branchId): bool {
                $hasAllBranches = $membership->roles->contains(
                    fn ($role): bool => $role->is_active && $role->grantsAllBranches()
                );

                $hasCurrentBranch = $membership->branches->contains(
                    fn ($branch): bool => (int) $branch->id === $branchId
                );

                return $hasAllBranches || $hasCurrentBranch;
            })
            ->values()
            ->map(fn (Membership $membership): array => [
                'ulid' => $membership->ulid,
                'username' => $membership->username,
                'name' => $membership->user?->name ?? $membership->username,
            ])
            ->all();
    }

    public function store(StoreSaleRequest $request, CreateSaleAction $create): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        $sale = $create->execute($request->validated(), $idempotencyKey);

        return (new SaleResource($sale))->response()->setStatusCode(201);
    }

    public function show(string $saleUlid, TenantContext $tenantContext): SaleResource
    {
        $sale = $this->find($saleUlid, $tenantContext);
        $this->authorize('view', $sale);

        return new SaleResource(
            $sale->load([...CreateSaleAction::with(), 'payments', 'payments.account'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payments(string $saleUlid, TenantContext $tenantContext): mixed
    {
        $sale = $this->find($saleUlid, $tenantContext);
        $this->authorize('view', $sale);

        return SalePaymentResource::collection(
            SalePayment::query()
                ->forTenant($tenantContext->tenantId())
                ->where('sale_id', $sale->id)
                ->orderBy('id')
                ->get()
        );
    }

    public function storePayment(
        StoreSalePaymentRequest $request,
        string $saleUlid,
        TenantContext $tenantContext,
        CollectSalePaymentAction $collect,
    ): JsonResponse {
        $sale = $this->find($saleUlid, $tenantContext);
        $this->authorize('createPayment', $sale);

        $payment = $collect->execute(
            $sale,
            $request->validated(),
            (string) $request->header('Idempotency-Key', ''),
        );

        return (new SalePaymentResource($payment))->response()->setStatusCode(201);
    }

    private function find(string $ulid, TenantContext $tenantContext): Sale
    {
        $sale = Sale::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $sale) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $sale;
    }
}
