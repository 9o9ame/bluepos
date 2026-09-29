<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coa\StoreAccountTypeRequest;
use App\Http\Requests\Coa\UpdateAccountTypeRequest;
use App\Http\Resources\AccountTypeResource;
use App\Models\AccountMainHead;
use App\Models\AccountType;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountTypeController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', AccountType::class);

        return AccountTypeResource::collection(
            AccountType::query()
                ->forTenant($tenantContext->tenantId())
                ->with(['subHead.mainHead'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function tree(TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('viewAny', AccountMainHead::class);

        $mains = AccountMainHead::query()
            ->forTenant($tenantContext->tenantId())
            ->with([
                'subHeads' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')
                    ->with(['accountTypes' => fn ($aq) => $aq->orderBy('sort_order')->orderBy('name')]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $data = $mains->map(function (AccountMainHead $main) {
            return [
                'ulid' => $main->ulid,
                'name' => $main->name,
                'sort_order' => (int) $main->sort_order,
                'is_active' => (bool) $main->is_active,
                'sub_heads' => $main->subHeads->map(function ($sub) {
                    return [
                        'ulid' => $sub->ulid,
                        'name' => $sub->name,
                        'sort_order' => (int) $sub->sort_order,
                        'is_active' => (bool) $sub->is_active,
                        'account_types' => $sub->accountTypes->map(function (AccountType $type) {
                            return [
                                'ulid' => $type->ulid,
                                'code' => $type->code,
                                'name' => $type->name,
                                'sort_order' => (int) $type->sort_order,
                                'is_active' => (bool) $type->is_active,
                                'is_cash' => (bool) $type->is_cash,
                                'is_bank' => (bool) $type->is_bank,
                                'is_receivable' => (bool) $type->is_receivable,
                                'is_payable' => (bool) $type->is_payable,
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreAccountTypeRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('create', AccountType::class);

        $subHead = $this->catalog->accountSubHead((string) $request->validated('sub_head_ulid'));

        $accountType = DB::transaction(function () use ($request, $tenantContext, $subHead): AccountType {
            $exists = AccountType::query()
                ->forTenant($tenantContext->tenantId())
                ->where('sub_head_id', $subHead->id)
                ->where('name', $request->validated('name'))
                ->exists();
            if ($exists) {
                throw new ApiException('VALIDATION_FAILED', 'Account Type name already exists under this Sub Head.', 422);
            }

            $accountType = AccountType::query()->create([
                'tenant_id' => $tenantContext->tenantId(),
                'sub_head_id' => $subHead->id,
                'code' => $request->validated('code'),
                'name' => $request->validated('name'),
                'is_cash' => $request->boolean('is_cash', false),
                'is_bank' => $request->boolean('is_bank', false),
                'is_receivable' => $request->boolean('is_receivable', false),
                'is_payable' => $request->boolean('is_payable', false),
                'pnl_grouping_label' => $request->validated('pnl_grouping_label'),
                'hint' => $request->validated('hint'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->audit->record('COA_ACCOUNT_TYPE_CREATED', [
                'resource_type' => 'account_type',
                'resource_ulid' => $accountType->ulid,
            ]);

            return $accountType;
        });

        return (new AccountTypeResource($accountType->load(['subHead.mainHead'])))->response()->setStatusCode(201);
    }

    public function show(string $accountTypeUlid): AccountTypeResource
    {
        $accountType = $this->catalog->accountType($accountTypeUlid);
        $this->authorize('view', $accountType);

        return new AccountTypeResource($accountType->load(['subHead.mainHead']));
    }

    public function update(UpdateAccountTypeRequest $request, string $accountTypeUlid): AccountTypeResource
    {
        $accountType = $this->catalog->accountType($accountTypeUlid);
        $this->authorize('update', $accountType);

        DB::transaction(function () use ($accountType, $request): void {
            $data = $request->safe()->except(['sub_head_ulid']);
            if ($request->filled('sub_head_ulid')) {
                $subHead = $this->catalog->accountSubHead((string) $request->validated('sub_head_ulid'));
                $data['sub_head_id'] = $subHead->id;
            }
            $accountType->fill($data);
            $accountType->save();
            $this->audit->record('COA_ACCOUNT_TYPE_UPDATED', [
                'resource_type' => 'account_type',
                'resource_ulid' => $accountType->ulid,
            ]);
        });

        return new AccountTypeResource($accountType->refresh()->load(['subHead.mainHead']));
    }

    public function destroy(string $accountTypeUlid): JsonResponse
    {
        $accountType = $this->catalog->accountType($accountTypeUlid);
        $this->authorize('delete', $accountType);

        DB::transaction(function () use ($accountType): void {
            $accountType->is_active = false;
            $accountType->save();
            $this->audit->record('COA_ACCOUNT_TYPE_DEACTIVATED', [
                'resource_type' => 'account_type',
                'resource_ulid' => $accountType->ulid,
            ]);
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }
}
