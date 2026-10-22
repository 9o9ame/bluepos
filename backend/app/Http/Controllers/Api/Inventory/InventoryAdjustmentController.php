<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Actions\Inventory\AmendInventoryAdjustmentAction;
use App\Actions\Inventory\CreateInventoryAdjustmentAction;
use App\Actions\Inventory\PostInventoryAdjustmentAction;
use App\Actions\Inventory\UpsertInventoryAdjustmentLineAction;
use App\Authz\PermissionService;
use App\Enums\InventoryAdjustmentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryAdjustmentLineResource;
use App\Http\Resources\Inventory\InventoryAdjustmentResource;
use App\Models\Account;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    public function nextDocumentNumber(
        CreateInventoryAdjustmentAction $create,
        PermissionService $permissions,
    ): array {
        abort_unless($permissions->can('inventory.adjust'), 403);

        return ['document_number' => $create->previewNextDocumentNumber()];
    }

    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', InventoryAdjustment::class);

        $query = InventoryAdjustment::query()
            ->forTenant($tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->with(['warehouse', 'account', 'lines.product', 'latestRevision'])
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $needle = trim((string) $request->string('q'));
            if ($needle !== '') {
                $query->where('document_number', 'ilike', '%'.$needle.'%');
            }
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('from_date')) {
            $fromDate = (string) $request->string('from_date');
            $query->where(function ($effective) use ($fromDate): void {
                $effective
                    ->where(function ($base) use ($fromDate): void {
                        $base
                            ->whereDoesntHave('latestRevision')
                            ->whereDate('adjustment_date', '>=', $fromDate);
                    })
                    ->orWhereHas('latestRevision', function ($revision) use ($fromDate): void {
                        $revision->whereRaw(
                            "(after_snapshot->>'adjustment_date')::date >= ?::date",
                            [$fromDate],
                        );
                    });
            });
        }

        if ($request->filled('to_date')) {
            $toDate = (string) $request->string('to_date');
            $query->where(function ($effective) use ($toDate): void {
                $effective
                    ->where(function ($base) use ($toDate): void {
                        $base
                            ->whereDoesntHave('latestRevision')
                            ->whereDate('adjustment_date', '<=', $toDate);
                    })
                    ->orWhereHas('latestRevision', function ($revision) use ($toDate): void {
                        $revision->whereRaw(
                            "(after_snapshot->>'adjustment_date')::date <= ?::date",
                            [$toDate],
                        );
                    });
            });
        }

        if ($request->filled('product_ulid')) {
            $query->whereHas('lines.product', fn ($products) => $products
                ->where('ulid', (string) $request->string('product_ulid')));
        }

        return InventoryAdjustmentResource::collection($query->limit(200)->get());
    }

    public function accounts(TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('viewAny', InventoryAdjustment::class);

        $rows = Account::query()
            ->forTenant($tenantContext->tenantId())
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('name')
            ->get(['ulid', 'code', 'name'])
            ->map(fn (Account $account) => [
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function store(
        Request $request,
        CreateInventoryAdjustmentAction $create,
    ): JsonResponse {
        $this->authorize('create', InventoryAdjustment::class);

        $data = $request->validate([
            'account_ulid' => ['required', 'string', 'size:26'],
            'adjustment_date' => ['nullable', 'date'],
            'adjustment_type' => ['nullable', 'in:stock_adjustment'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return (new InventoryAdjustmentResource($create->execute($data)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        string $adjustmentUlid,
        TenantContext $tenantContext,
    ): InventoryAdjustmentResource {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('view', $document);

        return new InventoryAdjustmentResource(
            $document->load(['warehouse', 'account', 'lines.product', 'latestRevision']),
        );
    }

    public function update(
        Request $request,
        string $adjustmentUlid,
        TenantContext $tenantContext,
    ): InventoryAdjustmentResource {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'account_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'adjustment_date' => ['sometimes', 'required', 'date'],
            'adjustment_type' => ['sometimes', 'required', 'in:stock_adjustment'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $document = DB::transaction(function () use ($document, $data, $tenantContext): InventoryAdjustment {
            $locked = InventoryAdjustment::query()
                ->forTenant($tenantContext->tenantId())
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== InventoryAdjustmentStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted inventory adjustments cannot be edited.', 422);
            }

            if (array_key_exists('account_ulid', $data)) {
                $account = Account::query()
                    ->forTenant($tenantContext->tenantId())
                    ->where('ulid', $data['account_ulid'])
                    ->where('is_active', true)
                    ->first();

                if (! $account) {
                    throw new ApiException('NOT_FOUND', 'The selected account was not found.', 404);
                }
                $locked->account_id = $account->id;
            }

            if (array_key_exists('adjustment_date', $data)) {
                $locked->adjustment_date = $data['adjustment_date'];
            }
            if (array_key_exists('adjustment_type', $data)) {
                $locked->adjustment_type = $data['adjustment_type'];
            }
            if (array_key_exists('notes', $data)) {
                $locked->notes = $data['notes'];
            }

            $locked->save();

            return $locked->fresh(['warehouse', 'account', 'lines.product']) ?? $locked;
        });

        return new InventoryAdjustmentResource($document);
    }

    public function amend(
        Request $request,
        string $adjustmentUlid,
        TenantContext $tenantContext,
        AmendInventoryAdjustmentAction $amend,
    ): InventoryAdjustmentResource {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate([
            'revision_reason' => ['required', 'string', 'max:500'],
            'account_ulid' => ['required', 'string', 'size:26'],
            'adjustment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_ulid' => ['required', 'string', 'size:26'],
            'lines.*.qty_plus' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'lines.*.qty_minus' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new InventoryAdjustmentResource(
            $amend->execute(
                $document,
                $data,
                (string) $request->header('Idempotency-Key', ''),
            ),
        );
    }

    public function destroy(
        string $adjustmentUlid,
        TenantContext $tenantContext,
    ): JsonResponse {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('delete', $document);

        DB::transaction(function () use ($document): void {
            $locked = InventoryAdjustment::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== InventoryAdjustmentStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted inventory adjustments cannot be deleted.', 422);
            }

            InventoryAdjustmentLine::query()
                ->where('adjustment_id', $locked->id)
                ->delete();

            $locked->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function storeLine(
        Request $request,
        string $adjustmentUlid,
        TenantContext $tenantContext,
        UpsertInventoryAdjustmentLineAction $upsert,
    ): JsonResponse {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('update', $document);

        $data = $request->validate($this->lineRules());

        return (new InventoryAdjustmentLineResource($upsert->execute($document, $data)))
            ->response()
            ->setStatusCode(201);
    }

    public function updateLine(
        Request $request,
        string $adjustmentUlid,
        string $lineUlid,
        TenantContext $tenantContext,
        UpsertInventoryAdjustmentLineAction $upsert,
    ): InventoryAdjustmentLineResource {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);
        $line->loadMissing('product');

        $data = $request->validate([
            'product_ulid' => ['sometimes', 'required', 'string', 'size:26'],
            'qty_plus' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'qty_minus' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $delta = bcadd((string) $line->quantity_delta, '0', 6);
        $payload = [
            'product_ulid' => $data['product_ulid'] ?? $line->product->ulid,
            'qty_plus' => array_key_exists('qty_plus', $data)
                ? $data['qty_plus']
                : (bccomp($delta, '0', 6) > 0 ? $delta : '0'),
            'qty_minus' => array_key_exists('qty_minus', $data)
                ? $data['qty_minus']
                : (bccomp($delta, '0', 6) < 0 ? bcsub('0.000000', $delta, 6) : '0'),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes,
        ];

        if (array_key_exists('qty_plus', $data) && ! array_key_exists('qty_minus', $data)) {
            $payload['qty_minus'] = '0';
        }
        if (array_key_exists('qty_minus', $data) && ! array_key_exists('qty_plus', $data)) {
            $payload['qty_plus'] = '0';
        }

        return new InventoryAdjustmentLineResource(
            $upsert->execute($document, $payload, $line),
        );
    }

    public function destroyLine(
        string $adjustmentUlid,
        string $lineUlid,
        TenantContext $tenantContext,
    ): JsonResponse {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('update', $document);
        $line = $this->findLine($document, $lineUlid);

        DB::transaction(function () use ($document, $line): void {
            $locked = InventoryAdjustment::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== InventoryAdjustmentStatus::Draft) {
                throw new ApiException('DOCUMENT_POSTED', 'Posted inventory adjustments cannot be edited.', 422);
            }

            InventoryAdjustmentLine::query()
                ->whereKey($line->id)
                ->where('adjustment_id', $locked->id)
                ->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function post(
        string $adjustmentUlid,
        TenantContext $tenantContext,
        PostInventoryAdjustmentAction $post,
    ): InventoryAdjustmentResource {
        $document = $this->findDocument($adjustmentUlid, $tenantContext);
        $this->authorize('post', $document);

        return new InventoryAdjustmentResource($post->execute($document));
    }

    private function findDocument(
        string $ulid,
        TenantContext $tenantContext,
    ): InventoryAdjustment {
        $document = InventoryAdjustment::query()
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

    private function findLine(
        InventoryAdjustment $document,
        string $lineUlid,
    ): InventoryAdjustmentLine {
        $line = InventoryAdjustmentLine::query()
            ->where('adjustment_id', $document->id)
            ->where('ulid', $lineUlid)
            ->first();

        if (! $line) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $line;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lineRules(): array
    {
        return [
            'product_ulid' => ['required', 'string', 'size:26'],
            'qty_plus' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'qty_minus' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
