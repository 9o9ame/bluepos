<?php

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\CreateSaleReturnAction;
use App\Actions\Sales\DeleteSaleReturnLineAction;
use App\Actions\Sales\PostSaleReturnAction;
use App\Actions\Sales\RecalculateSaleReturnTotalsAction;
use App\Actions\Sales\UpdateSaleReturnAction;
use App\Actions\Sales\UpsertSaleReturnLineAction;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\SaleReturnLineResource;
use App\Http\Resources\Sales\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleReturnController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): array
    {
        $this->authorize('viewAny', SaleReturn::class);

        $perPage = min(max($request->integer('per_page', 40), 1), 100);

        $query = SaleReturn::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->with(['sale', 'customer', 'salesmanParty', 'branch', 'warehouse'])
            ->orderByDesc('return_date')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $raw = trim((string) $request->string('q'));
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $raw).'%';

            $query->where(function ($inner) use ($term): void {
                $inner
                    ->where('document_number', 'ilike', $term)
                    ->orWhereHas('sale', fn ($sale) => $sale->where('document_number', 'ilike', $term))
                    ->orWhereHas('customer', function ($customer) use ($term): void {
                        $customer->where('name', 'ilike', $term)
                            ->orWhere('code', 'ilike', $term);
                    })
                    ->orWhereHas('salesmanParty', function ($salesman) use ($term): void {
                        $salesman->where('name', 'ilike', $term)
                            ->orWhere('code', 'ilike', $term);
                    });
            });
        }

        if ($request->filled('status')) {
            $status = SaleReturnStatus::tryFrom((string) $request->string('status'));
            if (! $status) {
                throw new ApiException('VALIDATION_ERROR', 'Invalid sales return status filter.', 422);
            }
            $query->where('status', $status->value);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('return_date', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('return_date', '<=', (string) $request->string('date_to'));
        }

        $page = $query->paginate($perPage);

        return [
            'data' => SaleReturnResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function store(
        Request $request,
        CreateSaleReturnAction $create,
    ): JsonResponse {
        $this->authorize('create', SaleReturn::class);

        $data = $request->validate([
            'sale_ulid' => ['required', 'string', 'size:26'],
            'return_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $document = $create->execute(
            $data,
            (string) $request->header('Idempotency-Key', ''),
        );

        return (new SaleReturnResource($document))->response()->setStatusCode(201);
    }

    public function show(
        string $returnUlid,
        TenantContext $tenantContext,
    ): SaleReturnResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('view', $document);

        return new SaleReturnResource($document->load(CreateSaleReturnAction::with()));
    }

    public function update(
        Request $request,
        string $returnUlid,
        TenantContext $tenantContext,
        UpdateSaleReturnAction $update,
    ): SaleReturnResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'return_date' => ['sometimes', 'required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return new SaleReturnResource($update->execute($document, $data));
    }

    public function storeLine(
        Request $request,
        string $returnUlid,
        TenantContext $tenantContext,
        UpsertSaleReturnLineAction $upsert,
    ): JsonResponse {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'sale_item_ulid' => ['required', 'string', 'size:26'],
            'quantity' => [
                'required',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',
                'numeric',
                'gt:0',
            ],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $line = $upsert->execute($document, $data);

        return (new SaleReturnLineResource($line))->response()->setStatusCode(201);
    }

    public function updateLine(
        Request $request,
        string $returnUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        UpsertSaleReturnLineAction $upsert,
    ): SaleReturnLineResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);
        $line->loadMissing('saleItem');

        $data = $request->validate([
            'sale_item_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'quantity' => [
                'sometimes',
                'required',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',
                'numeric',
                'gt:0',
            ],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'sale_item_ulid' => $data['sale_item_ulid']
                ?? $line->saleItem?->ulid
                ?? '',
            'quantity' => $data['quantity'] ?? (string) $line->quantity,
            'reason' => array_key_exists('reason', $data) ? $data['reason'] : $line->reason,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes,
        ];

        return new SaleReturnLineResource($upsert->execute($document, $payload, $line));
    }

    public function destroyLine(
        string $returnUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        DeleteSaleReturnLineAction $delete,
    ): JsonResponse {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);

        $delete->execute($document, $line);

        return response()->json(['ok' => true]);
    }

    public function post(
        string $returnUlid,
        TenantContext $tenantContext,
        PostSaleReturnAction $post,
    ): SaleReturnResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('post', $document);

        return new SaleReturnResource($post->execute($document));
    }

    public function returnableLines(
        string $saleUlid,
        TenantContext $tenantContext,
        RecalculateSaleReturnTotalsAction $recalculate,
    ): JsonResponse {
        $this->authorize('viewAny', SaleReturn::class);

        $sale = Sale::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('ulid', $saleUlid)
            ->with([
                'customer',
                'salesmanParty',
                'items.product.category',
                'items.unit',
            ])
            ->first();

        if (! $sale) {
            throw new ApiException('NOT_FOUND', 'The requested sale was not found.', 404);
        }

        if ($sale->status !== SaleStatus::Posted) {
            throw new ApiException('DOCUMENT_NOT_POSTED', 'Only posted sales have returnable lines.', 422);
        }

        $rows = [];

        foreach ($sale->items as $item) {
            $remaining = $recalculate->remainingReturnableQuantity($item);
            $returned = bcsub((string) $item->quantity, $remaining, 6);

            $rows[] = [
                'sale_item_ulid' => $item->ulid,
                'line_kind' => $item->line_kind->value,
                'product' => [
                    'ulid' => $item->product?->ulid,
                    'product_number' => $item->product?->product_number,
                    'name' => $item->product?->name,
                    'category' => $item->product?->category ? [
                        'ulid' => $item->product->category->ulid,
                        'name' => $item->product->category->name,
                    ] : null,
                ],
                'unit' => $item->unit ? [
                    'ulid' => $item->unit->ulid,
                    'code' => $item->unit->code,
                    'name' => $item->unit->name,
                ] : null,
                'original_quantity' => $item->quantity,
                'already_returned_quantity' => bcadd($returned, '0', 6),
                'remaining_returnable_quantity' => $remaining,
                'conversion_factor' => $item->conversion_factor,
                'unit_price' => $item->unit_price,
                'gross_amount' => $item->gross_amount,
                'discount_amount' => $item->discount_amount,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ];
        }

        return response()->json([
            'sale' => [
                'ulid' => $sale->ulid,
                'document_number' => $sale->document_number,
                'sale_date' => $sale->sale_date?->toDateString(),
                'customer' => $sale->customer ? [
                    'ulid' => $sale->customer->ulid,
                    'code' => $sale->customer->code,
                    'name' => $sale->customer->name,
                ] : null,
                'salesman' => $sale->salesmanParty ? [
                    'ulid' => $sale->salesmanParty->ulid,
                    'code' => $sale->salesmanParty->code,
                    'name' => $sale->salesmanParty->name,
                ] : null,
                'grand_total' => $sale->grand_total,
            ],
            'data' => $rows,
        ]);
    }

    public function productWise(
        Request $request,
        TenantContext $tenantContext,
    ): array {
        $this->authorize('viewAny', SaleReturn::class);

        $perPage = min(max($request->integer('per_page', 50), 1), 100);

        $query = SaleReturnLine::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->whereHas('saleReturn', function ($return) use ($request, $tenantContext): void {
                $return
                    ->where('branch_id', $tenantContext->branchId())
                    ->where('warehouse_id', $tenantContext->warehouseId())
                    ->where('status', SaleReturnStatus::Posted->value);

                if ($request->filled('date_from')) {
                    $return->whereDate('return_date', '>=', (string) $request->string('date_from'));
                }

                if ($request->filled('date_to')) {
                    $return->whereDate('return_date', '<=', (string) $request->string('date_to'));
                }
            })
            ->with([
                'product.category',
                'saleReturn.customer',
                'saleReturn.salesmanParty',
            ])
            ->orderByDesc('id');

        $page = $query->paginate($perPage);

        $data = collect($page->items())->map(function (SaleReturnLine $line): array {
            return [
                'ulid' => $line->ulid,
                'return_ulid' => $line->saleReturn->ulid,
                'return_number' => $line->saleReturn->document_number,
                'return_date' => $line->saleReturn->return_date?->toDateString(),
                'customer' => $line->saleReturn->customer ? [
                    'ulid' => $line->saleReturn->customer->ulid,
                    'name' => $line->saleReturn->customer->name,
                ] : null,
                'salesman' => $line->saleReturn->salesmanParty ? [
                    'ulid' => $line->saleReturn->salesmanParty->ulid,
                    'name' => $line->saleReturn->salesmanParty->name,
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
                'quantity_in' => $line->quantity,
                'quantity_out' => '0.000000',
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

    private function findDocument(string $ulid, TenantContext $tenantContext): SaleReturn
    {
        $document = SaleReturn::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('ulid', $ulid)
            ->first();

        if (! $document) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $document;
    }

    private function findLine(SaleReturn $document, string $lineUlid): SaleReturnLine
    {
        $line = SaleReturnLine::query()
            ->where('sale_return_id', $document->id)
            ->where('ulid', $lineUlid)
            ->first();

        if (! $line) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $line;
    }
}
