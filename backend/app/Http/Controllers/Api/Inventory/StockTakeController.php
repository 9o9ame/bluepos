<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\CreateStockTakeAction;
use App\Actions\Inventory\PostStockTakeAction;
use App\Actions\Inventory\UpsertStockTakeLineAction;
use App\Enums\StockTakeStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\StockTakeLineResource;
use App\Http\Resources\Inventory\StockTakeResource;
use App\Models\InventoryStockTake;
use App\Models\InventoryStockTakeLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTakeController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', InventoryStockTake::class);

        $query = InventoryStockTake::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['warehouse', 'lines.product'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('warehouse_ulid')) {
            $query->whereHas('warehouse', fn ($q) => $q->where('ulid', (string) $request->string('warehouse_ulid')));
        }

        if ($request->filled('product_ulid')) {
            $query->whereHas('lines.product', fn ($q) => $q->where('ulid', (string) $request->string('product_ulid')));
        }

        return StockTakeResource::collection($query->limit(100)->get());
    }

    public function store(Request $request, CreateStockTakeAction $create): JsonResponse
    {
        $this->authorize('create', InventoryStockTake::class);

        $data = $request->validate([
            'warehouse_ulid' => ['required', 'string', 'size:26'],
            'count_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return (new StockTakeResource($create->execute($data)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $stockTakeUlid, TenantContext $tenantContext): StockTakeResource
    {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('view', $document);

        return new StockTakeResource($document->load(['warehouse', 'lines.product']));
    }

    public function update(
        Request $request,
        string $stockTakeUlid,
        TenantContext $tenantContext,
    ): StockTakeResource {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'count_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $document = DB::transaction(function () use ($document, $data): InventoryStockTake {
            $document = InventoryStockTake::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== StockTakeStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted stock takes cannot be edited.', 422);
            }

            if (array_key_exists('count_date', $data)) {
                $document->count_date = $data['count_date'];
            }
            if (array_key_exists('notes', $data)) {
                $document->notes = $data['notes'];
            }
            $document->save();

            return $document->fresh(['warehouse', 'lines.product']) ?? $document;
        });

        return new StockTakeResource($document);
    }

    public function destroy(string $stockTakeUlid, TenantContext $tenantContext): JsonResponse
    {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('delete', $document);

        DB::transaction(function () use ($document): void {
            $document = InventoryStockTake::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== StockTakeStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted stock takes cannot be deleted.', 422);
            }

            InventoryStockTakeLine::query()
                ->where('stock_take_id', $document->id)
                ->delete();
            $document->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function storeLine(
        Request $request,
        string $stockTakeUlid,
        TenantContext $tenantContext,
        UpsertStockTakeLineAction $upsert,
    ): JsonResponse {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'product_ulid' => ['required', 'string', 'size:26'],
            'counted_quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return (new StockTakeLineResource($upsert->execute($document, $data)))
            ->response()
            ->setStatusCode(201);
    }

    public function updateLine(
        Request $request,
        string $stockTakeUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        UpsertStockTakeLineAction $upsert,
    ): StockTakeLineResource {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);
        $line->loadMissing('product');

        $data = $request->validate([
            'product_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'counted_quantity' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'product_ulid' => $data['product_ulid'] ?? $line->product->ulid,
            'counted_quantity' => $data['counted_quantity'] ?? (string) $line->counted_quantity,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes,
        ];

        return new StockTakeLineResource($upsert->execute($document, $payload, $line));
    }

    public function destroyLine(
        string $stockTakeUlid,
        string $lineUlid,
        TenantContext $tenantContext,
    ): JsonResponse {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);

        DB::transaction(function () use ($document, $line): void {
            $locked = InventoryStockTake::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== StockTakeStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted stock takes cannot be edited.', 422);
            }

            InventoryStockTakeLine::query()
                ->whereKey($line->id)
                ->where('stock_take_id', $locked->id)
                ->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function post(
        string $stockTakeUlid,
        TenantContext $tenantContext,
        PostStockTakeAction $post,
    ): StockTakeResource {
        $document = $this->findDocument($stockTakeUlid, $tenantContext);
        $this->authorize('post', $document);

        return new StockTakeResource($post->execute($document));
    }

    private function findDocument(string $ulid, TenantContext $tenantContext): InventoryStockTake
    {
        $document = InventoryStockTake::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $document) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $document;
    }

    private function findLine(InventoryStockTake $document, string $lineUlid): InventoryStockTakeLine
    {
        $line = InventoryStockTakeLine::query()
            ->where('stock_take_id', $document->id)
            ->where('ulid', $lineUlid)
            ->first();

        if (! $line) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $line;
    }
}
