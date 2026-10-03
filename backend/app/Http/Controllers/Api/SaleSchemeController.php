<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaleSchemes\UpsertSaleSchemeAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaleSchemes\StoreSaleSchemeRequest;
use App\Http\Requests\SaleSchemes\UpdateSaleSchemeRequest;
use App\Http\Resources\SaleSchemeResource;
use App\Models\SaleScheme;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SaleSchemeController extends Controller
{
    public function __construct(
        private readonly UpsertSaleSchemeAction $upsert,
        private readonly AuditLogger $audit,
    ) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', SaleScheme::class);

        $rows = SaleScheme::query()
            ->forTenant($tenantContext->tenantId())
            ->with('rewardProduct')
            ->orderBy('name')
            ->get();

        return SaleSchemeResource::collection($rows);
    }

    public function store(StoreSaleSchemeRequest $request): JsonResponse
    {
        $this->authorize('create', SaleScheme::class);

        $scheme = $this->upsert->create($request->validated());

        $this->audit->record('SALE_SCHEME_CREATED', [
            'resource_type' => 'sale_scheme',
            'resource_ulid' => $scheme->ulid,
        ]);

        return (new SaleSchemeResource($scheme))->response()->setStatusCode(201);
    }

    public function show(string $schemeUlid, TenantContext $tenantContext): SaleSchemeResource
    {
        $scheme = $this->find($schemeUlid, $tenantContext);
        $this->authorize('view', $scheme);

        return new SaleSchemeResource($scheme->load('rewardProduct'));
    }

    public function update(UpdateSaleSchemeRequest $request, string $schemeUlid, TenantContext $tenantContext): SaleSchemeResource
    {
        $scheme = $this->find($schemeUlid, $tenantContext);
        $this->authorize('update', $scheme);

        $scheme = $this->upsert->update($scheme, $request->validated());

        $this->audit->record('SALE_SCHEME_UPDATED', [
            'resource_type' => 'sale_scheme',
            'resource_ulid' => $scheme->ulid,
        ]);

        return new SaleSchemeResource($scheme);
    }

    public function destroy(string $schemeUlid, TenantContext $tenantContext): JsonResponse
    {
        $scheme = $this->find($schemeUlid, $tenantContext);
        $this->authorize('delete', $scheme);

        DB::transaction(function () use ($scheme): void {
            $scheme->is_active = false;
            $scheme->updated_by = app(TenantContext::class)->userId();
            $scheme->save();
            $this->audit->record('SALE_SCHEME_DEACTIVATED', [
                'resource_type' => 'sale_scheme',
                'resource_ulid' => $scheme->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }

    private function find(string $ulid, TenantContext $tenantContext): SaleScheme
    {
        $scheme = SaleScheme::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $scheme) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $scheme;
    }
}
