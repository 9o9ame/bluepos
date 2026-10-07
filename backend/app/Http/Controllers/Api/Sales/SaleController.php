<?php

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\CollectSalePaymentAction;
use App\Actions\Sales\CreateSaleAction;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalePaymentRequest;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Resources\Sales\SalePaymentResource;
use App\Http\Resources\Sales\SaleResource;
use App\Models\PartyProfile;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Enums\SaleStatus;
use App\Enums\SaleReturnStatus;
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
            ->where('branch_id', $tenantContext->branchId())
            ->with(['customer', 'salesmanParty', 'branch', 'warehouse'])
            ->withSum('payments as paid_amount', 'amount')
            ->withSum([
                'saleReturns as returned_amount' => fn ($q) => $q->where('status', SaleReturnStatus::Posted->value),
            ], 'grand_total')
            ->orderByDesc('sale_date')
            ->orderByDesc('id');

        if ($request->filled('customer_ulid')) {
            $query->whereHas('customer', fn ($q) => $q->where('ulid', (string) $request->string('customer_ulid')));
        }

        if ($request->filled('salesman_ulid')) {
            $query->whereHas(
                'salesmanParty',
                fn ($q) => $q->where('ulid', (string) $request->string('salesman_ulid'))
            );
        }

        if ($request->filled('status')) {
            $status = SaleStatus::tryFrom((string) $request->string('status'));

            if (! $status) {
                throw new ApiException('VALIDATION_ERROR', 'Invalid sale status filter.', 422);
            }

            $query->where('status', $status->value);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));

            if ($term !== '') {
                $query->where(function ($saleQuery) use ($term): void {
                    $saleQuery
                        ->where('document_number', 'ilike', '%'.$term.'%')
                        ->orWhereHas('customer', function ($customerQuery) use ($term): void {
                            $customerQuery
                                ->where('code', 'ilike', '%'.$term.'%')
                                ->orWhere('name', 'ilike', '%'.$term.'%');
                        })
                        ->orWhereHas('salesmanParty', function ($salesmanQuery) use ($term): void {
                            $salesmanQuery
                                ->where('code', 'ilike', '%'.$term.'%')
                                ->orWhere('name', 'ilike', '%'.$term.'%');
                        });
                });
            }
        }

        if ($request->boolean('due_only')) {
            $query
                ->where('status', SaleStatus::Posted->value)
                ->whereRaw(
                    '(sales.grand_total - COALESCE((
                        SELECT SUM(sr.grand_total)
                        FROM sale_returns sr
                        WHERE sr.sale_id = sales.id
                          AND sr.tenant_id = sales.tenant_id
                          AND sr.status = ?
                    ), 0)) > COALESCE((
                        SELECT SUM(sp.amount)
                        FROM sale_payments sp
                        WHERE sp.sale_id = sales.id
                          AND sp.tenant_id = sales.tenant_id
                    ), 0)',
                    [SaleReturnStatus::Posted->value],
                );
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

    public function productWise(
        Request $request,
        TenantContext $tenantContext,
    ): array {
        $this->authorize('viewAny', Sale::class);

        $perPage = min(max($request->integer('per_page', 50), 1), 100);

        $query = SaleItem::query()
            ->forTenant($tenantContext->tenantId())
            ->whereHas('sale', function ($sale) use ($request, $tenantContext): void {
                $sale
                    ->where('branch_id', $tenantContext->branchId())
                    ->where('warehouse_id', $tenantContext->warehouseId())
                    ->where('status', SaleStatus::Posted->value);

                if ($request->filled('date_from')) {
                    $sale->whereDate('sale_date', '>=', (string) $request->string('date_from'));
                }

                if ($request->filled('date_to')) {
                    $sale->whereDate('sale_date', '<=', (string) $request->string('date_to'));
                }
            })
            ->with([
                'product.category',
                'sale.customer',
                'sale.salesmanParty',
                'unit',
            ])
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));

            if ($raw !== '') {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $raw).'%';

                $query->where(function ($line) use ($term): void {
                    $line
                        ->whereHas('product', function ($product) use ($term): void {
                            $product
                                ->where('product_number', 'ilike', $term)
                                ->orWhere('name', 'ilike', $term)
                                ->orWhereHas('category', fn ($category) => $category->where('name', 'ilike', $term));
                        })
                        ->orWhereHas('sale', function ($sale) use ($term): void {
                            $sale
                                ->where('document_number', 'ilike', $term)
                                ->orWhereHas('customer', function ($customer) use ($term): void {
                                    $customer
                                        ->where('code', 'ilike', $term)
                                        ->orWhere('name', 'ilike', $term);
                                })
                                ->orWhereHas('salesmanParty', function ($salesman) use ($term): void {
                                    $salesman
                                        ->where('code', 'ilike', $term)
                                        ->orWhere('name', 'ilike', $term);
                                });
                        });
                });
            }
        }

        $page = $query->paginate($perPage);

        $data = collect($page->items())->map(function (SaleItem $line): array {
            return [
                'ulid' => $line->ulid,
                'sale_ulid' => $line->sale->ulid,
                'sale_number' => $line->sale->document_number,
                'sale_date' => $line->sale->sale_date?->toDateString(),
                'customer' => $line->sale->customer ? [
                    'ulid' => $line->sale->customer->ulid,
                    'name' => $line->sale->customer->name,
                ] : null,
                'salesman' => $line->sale->salesmanParty ? [
                    'ulid' => $line->sale->salesmanParty->ulid,
                    'name' => $line->sale->salesmanParty->name,
                ] : null,
                'product' => [
                    'ulid' => $line->product->ulid,
                    'product_number' => $line->product->product_number,
                    'name' => $line->product->name,
                ],
                'category' => $line->product->category ? [
                    'ulid' => $line->product->category->ulid,
                    'name' => $line->product->category->name,
                ] : null,
                'unit' => $line->unit ? [
                    'ulid' => $line->unit->ulid,
                    'code' => $line->unit->code,
                    'name' => $line->unit->name,
                ] : null,
                'line_kind' => $line->line_kind->value,
                'quantity_out' => $line->quantity,
                'amount' => $line->line_total,
            ];
        })->values();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function salesmen(TenantContext $tenantContext, PermissionService $permissions): array
    {
        abort_unless($permissions->can('sales.create') || $permissions->can('sales.view'), 403);

        return PartyProfile::query()
            ->forTenant($tenantContext->tenantId())
            ->where('is_active', true)
            ->whereHas('types', fn ($q) => $q->where('type', 'salesman'))
            ->orderBy('name')
            ->get()
            ->map(fn (PartyProfile $profile): array => [
                'ulid' => $profile->ulid,
                'code' => $profile->code,
                'name' => $profile->name,
                'address' => $profile->address,
                'mobile' => $profile->mobile ?: $profile->phone,
            ])
            ->all();
    }

    public function store(
        StoreSaleRequest $request,
        CreateSaleAction $create,
        PermissionService $permissions,
    ): JsonResponse {
        $this->authorize('create', Sale::class);

        if ($request->has('initial_payment')) {
            abort_unless($permissions->can('payments.create'), 403);
        }

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
            ->where('branch_id', $tenantContext->branchId())
            ->where('ulid', $ulid)
            ->first();

        if (! $sale) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $sale;
    }
}
