<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coa\StoreAccountMainHeadRequest;
use App\Http\Requests\Coa\UpdateAccountMainHeadRequest;
use App\Http\Resources\AccountMainHeadResource;
use App\Models\AccountMainHead;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountMainHeadController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', AccountMainHead::class);

        return AccountMainHeadResource::collection(
            AccountMainHead::query()
                ->forTenant($tenantContext->tenantId())
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreAccountMainHeadRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', AccountMainHead::class);

        $mainHead = DB::transaction(function () use ($request, $tenantContext): AccountMainHead {
            $mainHead = AccountMainHead::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                'name' => $request->validated('name'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->audit->record('COA_MAIN_HEAD_CREATED', [
                'resource_type' => 'account_main_head',
                'resource_ulid' => $mainHead->ulid,
            ]);

            return $mainHead;
        });

        return (new AccountMainHeadResource($mainHead))->response()->setStatusCode(201);
    }

    public function show(string $mainHeadUlid): AccountMainHeadResource
    {
        $mainHead = $this->catalog->accountMainHead($mainHeadUlid);
        $this->authorize('view', $mainHead);

        return new AccountMainHeadResource($mainHead);
    }

    public function update(UpdateAccountMainHeadRequest $request, string $mainHeadUlid): AccountMainHeadResource
    {
        $mainHead = $this->catalog->accountMainHead($mainHeadUlid);
        $this->authorize('update', $mainHead);

        DB::transaction(function () use ($mainHead, $request): void {
            $mainHead->fill($request->validated());
            $mainHead->save();
            $this->audit->record('COA_MAIN_HEAD_UPDATED', [
                'resource_type' => 'account_main_head',
                'resource_ulid' => $mainHead->ulid,
            ]);
        });

        return new AccountMainHeadResource($mainHead->refresh());
    }

    public function destroy(string $mainHeadUlid): JsonResponse
    {
        $mainHead = $this->catalog->accountMainHead($mainHeadUlid);
        $this->authorize('delete', $mainHead);

        if ($mainHead->subHeads()->where('is_active', true)->exists()) {
            throw new ApiException(
                'RESOURCE_IN_USE',
                'Main Head has active Sub Heads and cannot be deactivated.',
                409,
            );
        }

        DB::transaction(function () use ($mainHead): void {
            $mainHead->is_active = false;
            $mainHead->save();
            $this->audit->record('COA_MAIN_HEAD_DEACTIVATED', [
                'resource_type' => 'account_main_head',
                'resource_ulid' => $mainHead->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
