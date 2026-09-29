<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coa\StoreAccountSubHeadRequest;
use App\Http\Requests\Coa\UpdateAccountSubHeadRequest;
use App\Http\Resources\AccountSubHeadResource;
use App\Models\AccountSubHead;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountSubHeadController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', AccountSubHead::class);

        return AccountSubHeadResource::collection(
            AccountSubHead::query()
                ->forTenant($tenantContext->tenantId())
                ->with('mainHead')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreAccountSubHeadRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', AccountSubHead::class);

        $mainHead = $this->catalog->accountMainHead((string) $request->validated('main_head_ulid'));

        $subHead = DB::transaction(function () use ($request, $tenantContext, $mainHead): AccountSubHead {
            $exists = AccountSubHead::query()
                ->forTenant($tenantContext->tenantId())
                ->where('main_head_id', $mainHead->id)
                ->where('name', $request->validated('name'))
                ->exists();
            if ($exists) {
                throw new ApiException('VALIDATION_FAILED', 'Sub Head name already exists under this Main Head.', 422);
            }

            $subHead = AccountSubHead::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                'main_head_id' => $mainHead->id,
                'name' => $request->validated('name'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->audit->record('COA_SUB_HEAD_CREATED', [
                'resource_type' => 'account_sub_head',
                'resource_ulid' => $subHead->ulid,
            ]);

            return $subHead;
        });

        return (new AccountSubHeadResource($subHead->load('mainHead')))->response()->setStatusCode(201);
    }

    public function show(string $subHeadUlid): AccountSubHeadResource
    {
        $subHead = $this->catalog->accountSubHead($subHeadUlid);
        $this->authorize('view', $subHead);

        return new AccountSubHeadResource($subHead->load('mainHead'));
    }

    public function update(UpdateAccountSubHeadRequest $request, string $subHeadUlid): AccountSubHeadResource
    {
        $subHead = $this->catalog->accountSubHead($subHeadUlid);
        $this->authorize('update', $subHead);

        DB::transaction(function () use ($subHead, $request): void {
            $data = $request->safe()->except(['main_head_ulid']);
            if ($request->filled('main_head_ulid')) {
                $mainHead = $this->catalog->accountMainHead((string) $request->validated('main_head_ulid'));
                $data['main_head_id'] = $mainHead->id;
            }
            $subHead->fill($data);
            $subHead->save();
            $this->audit->record('COA_SUB_HEAD_UPDATED', [
                'resource_type' => 'account_sub_head',
                'resource_ulid' => $subHead->ulid,
            ]);
        });

        return new AccountSubHeadResource($subHead->refresh()->load('mainHead'));
    }

    public function destroy(string $subHeadUlid): JsonResponse
    {
        $subHead = $this->catalog->accountSubHead($subHeadUlid);
        $this->authorize('delete', $subHead);

        if ($subHead->accountTypes()->where('is_active', true)->exists()) {
            throw new ApiException(
                'RESOURCE_IN_USE',
                'Sub Head has active Account Types and cannot be deactivated.',
                409,
            );
        }

        DB::transaction(function () use ($subHead): void {
            $subHead->is_active = false;
            $subHead->save();
            $this->audit->record('COA_SUB_HEAD_DEACTIVATED', [
                'resource_type' => 'account_sub_head',
                'resource_ulid' => $subHead->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
