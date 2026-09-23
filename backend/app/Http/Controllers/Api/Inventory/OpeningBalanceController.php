<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\CreateOpeningBalanceAction;
use App\Actions\Inventory\DeleteOpeningBalanceLineAction;
use App\Actions\Inventory\PostOpeningBalanceAction;
use App\Actions\Inventory\UpdateOpeningBalanceAction;
use App\Actions\Inventory\UpsertOpeningBalanceLineAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\OpeningBalanceLineResource;
use App\Http\Resources\Inventory\OpeningBalanceResource;
use App\Models\InventoryOpeningBalance;
use App\Models\InventoryOpeningBalanceLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpeningBalanceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', InventoryOpeningBalance::class);

        $query = InventoryOpeningBalance::query()
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

        return OpeningBalanceResource::collection($query->limit(100)->get());
    }

    public function store(Request $request, CreateOpeningBalanceAction $create): JsonResponse
    {
        $this->authorize('create', InventoryOpeningBalance::class);

        $data = $request->validate([
            'warehouse_ulid' => ['required', 'string', 'size:26'],
            'document_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $document = $create->execute($data);

        return (new OpeningBalanceResource($document))->response()->setStatusCode(201);
    }

    public function show(string $openingBalanceUlid, TenantContext $tenantContext): OpeningBalanceResource
    {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('view', $document);

        return new OpeningBalanceResource($document->load(['warehouse', 'lines.product']));
    }

    public function update(
        Request $request,
        string $openingBalanceUlid,
        TenantContext $tenantContext,
        UpdateOpeningBalanceAction $update,
    ): OpeningBalanceResource {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'document_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new OpeningBalanceResource($update->execute($document, $data));
    }

    public function destroy(string $openingBalanceUlid, TenantContext $tenantContext): JsonResponse
    {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('delete', $document);

        if (! $document->isDraft()) {
            throw new ApiException('DOCUMENT_POSTED', 'Posted opening balances cannot be deleted.', 422);
        }

        DB::transaction(function () use ($document): void {
            InventoryOpeningBalanceLine::query()
                ->where('opening_balance_id', $document->id)
                ->delete();
            $document->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function storeLine(
        Request $request,
        string $openingBalanceUlid,
        TenantContext $tenantContext,
        UpsertOpeningBalanceLineAction $upsert,
    ): JsonResponse {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'product_ulid' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'unit_cost' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $line = $upsert->execute($document, $data);

        return (new OpeningBalanceLineResource($line))->response()->setStatusCode(201);
    }

    public function updateLine(
        Request $request,
        string $openingBalanceUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        UpsertOpeningBalanceLineAction $upsert,
    ): OpeningBalanceLineResource {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);
        $line->loadMissing('product');

        $data = $request->validate([
            'product_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'quantity' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'unit_cost' => ['sometimes', 'required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'product_ulid' => $data['product_ulid'] ?? $line->product->ulid,
            'quantity' => $data['quantity'] ?? (string) $line->quantity,
            'unit_cost' => $data['unit_cost'] ?? (string) $line->unit_cost,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes,
        ];

        return new OpeningBalanceLineResource($upsert->execute($document, $payload, $line));
    }

    public function destroyLine(
        string $openingBalanceUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        DeleteOpeningBalanceLineAction $delete,
    ): JsonResponse {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);
        $delete->execute($document, $line);

        return response()->json(['ok' => true]);
    }

    public function post(
        string $openingBalanceUlid,
        TenantContext $tenantContext,
        PostOpeningBalanceAction $post,
    ): OpeningBalanceResource {
        $document = $this->findDocument($openingBalanceUlid, $tenantContext);
        $this->authorize('post', $document);

        return new OpeningBalanceResource($post->execute($document));
    }

    private function findDocument(string $ulid, TenantContext $tenantContext): InventoryOpeningBalance
    {
        $document = InventoryOpeningBalance::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $document) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $document;
    }

    private function findLine(InventoryOpeningBalance $document, string $lineUlid): InventoryOpeningBalanceLine
    {
        $line = InventoryOpeningBalanceLine::query()
            ->where('opening_balance_id', $document->id)
            ->where('ulid', $lineUlid)
            ->first();

        if (! $line) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $line;
    }
}
