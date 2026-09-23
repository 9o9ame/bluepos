<?php

namespace App\Http\Controllers\Api\Purchases;

use App\Actions\Purchases\CreatePurchaseReturnAction;
use App\Actions\Purchases\DeletePurchaseReturnLineAction;
use App\Actions\Purchases\PostPurchaseReturnAction;
use App\Actions\Purchases\RecalculatePurchaseReturnTotalsAction;
use App\Actions\Purchases\UpdatePurchaseReturnAction;
use App\Actions\Purchases\UpsertPurchaseReturnLineAction;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Purchases\PurchaseReturnLineResource;
use App\Http\Resources\Purchases\PurchaseReturnResource;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', PurchaseReturn::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = PurchaseReturn::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['supplier', 'warehouse', 'purchaseInvoice'])
            ->orderByDesc('return_date')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('document_number', 'ilike', $term)
                    ->orWhere('supplier_reference', 'ilike', $term)
                    ->orWhereHas('supplier', function ($supplier) use ($term): void {
                        $supplier->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term);
                    })
                    ->orWhereHas('purchaseInvoice', function ($purchase) use ($term): void {
                        $purchase->where('document_number', 'ilike', $term)
                            ->orWhere('supplier_invoice_number', 'ilike', $term);
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }
        if ($request->filled('supplier_ulid')) {
            $query->whereHas('supplier', fn ($q) => $q->where('ulid', (string) $request->string('supplier_ulid')));
        }
        if ($request->filled('warehouse_ulid')) {
            $query->whereHas('warehouse', fn ($q) => $q->where('ulid', (string) $request->string('warehouse_ulid')));
        }
        if ($request->filled('purchase_ulid')) {
            $query->whereHas('purchaseInvoice', fn ($q) => $q->where('ulid', (string) $request->string('purchase_ulid')));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('return_date', '>=', (string) $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('return_date', '<=', (string) $request->string('date_to'));
        }

        $page = $query->paginate($perPage);

        return [
            'data' => PurchaseReturnResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function store(Request $request, CreatePurchaseReturnAction $create): JsonResponse
    {
        $this->authorize('create', PurchaseReturn::class);

        $data = $request->validate([
            'purchase_ulid' => ['required', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
            'return_date' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $document = $create->execute($data);

        return (new PurchaseReturnResource($document))->response()->setStatusCode(201);
    }

    public function show(string $returnUlid, TenantContext $tenantContext): PurchaseReturnResource
    {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('view', $document);

        return new PurchaseReturnResource($document->load(CreatePurchaseReturnAction::with()));
    }

    public function update(
        Request $request,
        string $returnUlid,
        TenantContext $tenantContext,
        UpdatePurchaseReturnAction $update,
    ): PurchaseReturnResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'warehouse_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'return_date' => ['sometimes', 'required', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return new PurchaseReturnResource($update->execute($document, $data));
    }

    public function storeLine(
        Request $request,
        string $returnUlid,
        TenantContext $tenantContext,
        UpsertPurchaseReturnLineAction $upsert,
    ): JsonResponse {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'purchase_line_ulid' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'discount_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'tax_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $line = $upsert->execute($document, $data);

        return (new PurchaseReturnLineResource($line))->response()->setStatusCode(201);
    }

    public function updateLine(
        Request $request,
        string $returnUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        UpsertPurchaseReturnLineAction $upsert,
    ): PurchaseReturnLineResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);
        $line->loadMissing('purchaseInvoiceLine');

        $data = $request->validate([
            'purchase_line_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'quantity' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'discount_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'tax_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'purchase_line_ulid' => $data['purchase_line_ulid']
                ?? $line->purchaseInvoiceLine?->ulid
                ?? '',
            'quantity' => $data['quantity'] ?? (string) $line->quantity,
            'discount_amount' => array_key_exists('discount_amount', $data)
                ? $data['discount_amount']
                : (string) $line->discount_amount,
            'tax_amount' => array_key_exists('tax_amount', $data)
                ? $data['tax_amount']
                : (string) $line->tax_amount,
            'batch_number' => array_key_exists('batch_number', $data) ? $data['batch_number'] : $line->batch_number,
            'expiry_date' => array_key_exists('expiry_date', $data)
                ? $data['expiry_date']
                : ($line->expiry_date?->toDateString()),
            'reason' => array_key_exists('reason', $data) ? $data['reason'] : $line->reason,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes,
        ];

        return new PurchaseReturnLineResource($upsert->execute($document, $payload, $line));
    }

    public function destroyLine(
        string $returnUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        DeletePurchaseReturnLineAction $delete,
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
        PostPurchaseReturnAction $post,
    ): PurchaseReturnResource {
        $document = $this->findDocument($returnUlid, $tenantContext);
        $this->authorize('post', $document);

        return new PurchaseReturnResource($post->execute($document));
    }

    public function returnableLines(
        string $purchaseUlid,
        TenantContext $tenantContext,
        RecalculatePurchaseReturnTotalsAction $recalculate,
    ): JsonResponse {
        $this->authorize('viewAny', PurchaseReturn::class);

        $invoice = PurchaseInvoice::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['lines.product', 'lines.unit'])
            ->where('ulid', $purchaseUlid)
            ->first();

        if (! $invoice) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
            throw new ApiException('DOCUMENT_NOT_POSTED', 'Only posted purchases have returnable lines.', 422);
        }

        $rows = [];
        foreach ($invoice->lines as $line) {
            $remaining = $recalculate->remainingReturnableBase($line);
            $returned = bcsub((string) $line->base_quantity, $remaining, 6);
            $rows[] = [
                'purchase_line_ulid' => $line->ulid,
                'product' => [
                    'ulid' => $line->product?->ulid,
                    'product_number' => $line->product?->product_number,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'track_batch' => (bool) $line->product?->track_batch,
                    'track_expiry' => (bool) $line->product?->track_expiry,
                ],
                'unit' => [
                    'ulid' => $line->unit?->ulid,
                    'code' => $line->unit?->code,
                    'name' => $line->unit?->name,
                ],
                'original_quantity' => $line->quantity,
                'original_base_quantity' => $line->base_quantity,
                'already_returned_base_quantity' => bcadd($returned, '0', 6),
                'remaining_returnable_base_quantity' => $remaining,
                'unit_cost' => $line->unit_cost,
                'conversion_factor' => $line->conversion_factor,
                'batch_number' => $line->batch_number,
                'expiry_date' => $line->expiry_date?->toDateString(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    private function findDocument(string $ulid, TenantContext $tenantContext): PurchaseReturn
    {
        $document = PurchaseReturn::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $document) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $document;
    }

    private function findLine(PurchaseReturn $document, string $lineUlid): PurchaseReturnLine
    {
        $line = PurchaseReturnLine::query()
            ->where('purchase_return_id', $document->id)
            ->where('ulid', $lineUlid)
            ->first();

        if (! $line) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $line;
    }
}
