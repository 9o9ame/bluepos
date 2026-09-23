<?php

namespace App\Http\Controllers\Api\Purchases;

use App\Actions\Purchases\CreatePurchaseInvoiceAction;
use App\Actions\Purchases\DeletePurchaseInvoiceLineAction;
use App\Actions\Purchases\PostPurchaseInvoiceAction;
use App\Actions\Purchases\UpdatePurchaseInvoiceAction;
use App\Actions\Purchases\UpsertPurchaseInvoiceLineAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Purchases\PurchaseInvoiceLineResource;
use App\Http\Resources\Purchases\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', PurchaseInvoice::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = PurchaseInvoice::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['supplier', 'branch', 'warehouse'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('document_number', 'ilike', $term)
                    ->orWhere('supplier_invoice_number', 'ilike', $term)
                    ->orWhereHas('supplier', function ($supplier) use ($term): void {
                        $supplier->where('name', 'ilike', $term)
                            ->orWhere('code', 'ilike', $term);
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

        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', (string) $request->string('date_to'));
        }

        $page = $query->paginate($perPage);

        return [
            'data' => PurchaseInvoiceResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function store(Request $request, CreatePurchaseInvoiceAction $create): JsonResponse
    {
        $this->authorize('create', PurchaseInvoice::class);

        $data = $request->validate([
            'supplier_ulid' => ['required', 'string', 'size:26'],
            'warehouse_ulid' => ['required', 'string', 'size:26'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'freight_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'other_charges' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $invoice = $create->execute($data);

        return (new PurchaseInvoiceResource($invoice))->response()->setStatusCode(201);
    }

    public function show(string $purchaseUlid, TenantContext $tenantContext): PurchaseInvoiceResource
    {
        $invoice = $this->findInvoice($purchaseUlid, $tenantContext);
        $this->authorize('view', $invoice);

        return new PurchaseInvoiceResource($invoice->load(CreatePurchaseInvoiceAction::with()));
    }

    public function update(
        Request $request,
        string $purchaseUlid,
        TenantContext $tenantContext,
        UpdatePurchaseInvoiceAction $update,
    ): PurchaseInvoiceResource {
        $invoice = $this->findInvoice($purchaseUlid, $tenantContext);
        $this->authorize('update', $invoice);

        $data = $request->validate([
            'supplier_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'warehouse_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'invoice_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['nullable', 'date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'freight_amount' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'other_charges' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return new PurchaseInvoiceResource($update->execute($invoice, $data));
    }

    public function storeLine(
        Request $request,
        string $purchaseUlid,
        TenantContext $tenantContext,
        UpsertPurchaseInvoiceLineAction $upsert,
    ): JsonResponse {
        $invoice = $this->findInvoice($purchaseUlid, $tenantContext);
        $this->authorize('update', $invoice);

        $data = $request->validate([
            'product_ulid' => ['required', 'string', 'size:26'],
            'unit_ulid' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'conversion_factor' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/'],
            'unit_cost' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'discount_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'tax_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'supplier_product_code' => ['nullable', 'string', 'max:100'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $line = $upsert->execute($invoice, $data);

        return (new PurchaseInvoiceLineResource($line))->response()->setStatusCode(201);
    }

    public function updateLine(
        Request $request,
        string $purchaseUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        UpsertPurchaseInvoiceLineAction $upsert,
    ): PurchaseInvoiceLineResource {
        $invoice = $this->findInvoice($purchaseUlid, $tenantContext);
        $this->authorize('update', $invoice);
        $line = $this->findLine($invoice, $lineUlid);
        $line->loadMissing(['product', 'unit']);

        $data = $request->validate([
            'product_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'unit_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'quantity' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'conversion_factor' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/'],
            'unit_cost' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'discount_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'tax_amount' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'supplier_product_code' => ['nullable', 'string', 'max:100'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'product_ulid' => $data['product_ulid'] ?? $line->product->ulid,
            'unit_ulid' => $data['unit_ulid'] ?? $line->unit->ulid,
            'quantity' => $data['quantity'] ?? (string) $line->quantity,
            'conversion_factor' => $data['conversion_factor'] ?? (string) $line->conversion_factor,
            'unit_cost' => $data['unit_cost'] ?? (string) $line->unit_cost,
            'discount_amount' => $data['discount_amount'] ?? (string) $line->discount_amount,
            'tax_amount' => $data['tax_amount'] ?? (string) $line->tax_amount,
            'supplier_product_code' => array_key_exists('supplier_product_code', $data)
                ? $data['supplier_product_code']
                : $line->supplier_product_code,
            'batch_number' => array_key_exists('batch_number', $data) ? $data['batch_number'] : $line->batch_number,
            'expiry_date' => array_key_exists('expiry_date', $data)
                ? $data['expiry_date']
                : ($line->expiry_date?->toDateString()),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes,
        ];

        return new PurchaseInvoiceLineResource($upsert->execute($invoice, $payload, $line));
    }

    public function destroyLine(
        string $purchaseUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        DeletePurchaseInvoiceLineAction $delete,
    ): JsonResponse {
        $invoice = $this->findInvoice($purchaseUlid, $tenantContext);
        $this->authorize('update', $invoice);
        $line = $this->findLine($invoice, $lineUlid);
        $delete->execute($invoice, $line);

        return response()->json(['ok' => true]);
    }

    public function post(
        string $purchaseUlid,
        TenantContext $tenantContext,
        PostPurchaseInvoiceAction $post,
    ): PurchaseInvoiceResource {
        $invoice = $this->findInvoice($purchaseUlid, $tenantContext);
        $this->authorize('post', $invoice);

        return new PurchaseInvoiceResource($post->execute($invoice));
    }

    private function findInvoice(string $ulid, TenantContext $tenantContext): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $invoice) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $invoice;
    }

    private function findLine(PurchaseInvoice $invoice, string $lineUlid): PurchaseInvoiceLine
    {
        $line = PurchaseInvoiceLine::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->where('ulid', $lineUlid)
            ->first();

        if (! $line) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $line;
    }
}
