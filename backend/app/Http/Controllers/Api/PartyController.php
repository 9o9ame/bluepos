<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\StorePartyRequest;
use App\Http\Requests\Parties\UpdatePartyRequest;
use App\Http\Resources\PartyResource;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartyController extends Controller
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $type = strtolower(trim((string) $request->query('type', 'all')));
        if (! in_array($type, ['all', 'vendor', 'customer', 'account', 'salesman'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'Invalid party type filter.', 422);
        }

        $rows = collect();

        if (in_array($type, ['all', 'vendor'], true) && $this->canViewVendors()) {
            $vendors = Supplier::query()
                ->forTenant($tenantContext->tenantId())
                ->with('accountType')
                ->orderBy('name')
                ->get()
                ->map(fn (Supplier $supplier) => (new PartyResource($supplier, 'vendor'))->resolve());
            $rows = $rows->concat($vendors);
        }

        if (in_array($type, ['all', 'customer'], true) && $this->canViewCustomers()) {
            $customers = Customer::query()
                ->forTenant($tenantContext->tenantId())
                ->with('accountType')
                ->orderBy('name')
                ->get()
                ->map(fn (Customer $customer) => (new PartyResource($customer, 'customer'))->resolve());
            $rows = $rows->concat($customers);
        }

        if (in_array($type, ['all', 'account'], true) && $this->canViewAccounts()) {
            $accounts = Account::query()
                ->forTenant($tenantContext->tenantId())
                ->with('accountType')
                ->orderBy('name')
                ->get()
                ->map(fn (Account $account) => (new PartyResource($account, 'account'))->resolve());
            $rows = $rows->concat($accounts);
        }

        // salesman: no safe staff mapping yet — empty contribution.

        $sorted = $rows
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['name']), SORT_NATURAL)
            ->values()
            ->all();

        return response()->json(['data' => $sorted]);
    }

    public function store(StorePartyRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $partyType = (string) $request->validated('party_type');
        $this->authorizeCreate($partyType);

        $accountType = $this->catalog->accountType((string) $request->validated('account_type_ulid'));

        $party = DB::transaction(function () use ($request, $tenantContext, $partyType, $accountType): Supplier|Customer|Account {
            $payload = [
                'tenant_id' => $tenantContext->tenantId(),
                ...$request->partyAttributes(),
                'account_type_id' => $accountType->id,
                'is_active' => $request->boolean('is_active', true),
                'created_by' => $tenantContext->userId(),
            ];

            if ($partyType === 'vendor') {
                $party = Supplier::query()->create($payload);
                $this->audit->record('SUPPLIER_CREATED', [
                    'resource_type' => 'supplier',
                    'resource_ulid' => $party->ulid,
                ]);

                return $party->load('accountType');
            }

            if ($partyType === 'customer') {
                $party = Customer::query()->create($payload);
                $this->audit->record('CUSTOMER_CREATED', [
                    'resource_type' => 'customer',
                    'resource_ulid' => $party->ulid,
                ]);

                return $party->load('accountType');
            }

            $party = Account::query()->create([
                'tenant_id' => $payload['tenant_id'],
                'code' => $payload['code'],
                'name' => $payload['name'],
                'address' => $payload['address'] ?? null,
                'account_type_id' => $payload['account_type_id'],
                'is_active' => $payload['is_active'],
                'created_by' => $payload['created_by'],
            ]);
            $this->audit->record('ACCOUNT_CREATED', [
                'resource_type' => 'account',
                'resource_ulid' => $party->ulid,
            ]);

            return $party->load('accountType');
        });

        return (new PartyResource($party, $partyType))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $partyUlid): PartyResource
    {
        [$party, $partyType] = $this->resolveParty($request, $partyUlid);
        $this->authorize('view', $party);

        return new PartyResource($party->load('accountType'), $partyType);
    }

    public function update(UpdatePartyRequest $request, string $partyUlid): PartyResource
    {
        $partyType = (string) $request->validated('party_type');
        $party = match ($partyType) {
            'vendor' => $this->catalog->supplier($partyUlid),
            'customer' => $this->catalog->customer($partyUlid),
            'account' => $this->catalog->account($partyUlid),
            default => throw new ApiException('VALIDATION_FAILED', 'Invalid party type.', 422),
        };

        $this->authorize('update', $party);

        DB::transaction(function () use ($party, $request, $partyType): void {
            $wasActive = (bool) $party->is_active;
            $attrs = $request->partyAttributes();
            if ($request->filled('account_type_ulid')) {
                $accountType = $this->catalog->accountType((string) $request->validated('account_type_ulid'));
                $attrs['account_type_id'] = $accountType->id;
            }
            if ($partyType === 'account') {
                $attrs = array_intersect_key($attrs, array_flip(['code', 'name', 'address', 'account_type_id', 'is_active']));
            }
            $party->fill($attrs);
            $party->updated_by = app(TenantContext::class)->userId();
            $party->save();

            $becameActive = ! $wasActive && (bool) $party->is_active;
            $event = match ($partyType) {
                'vendor' => $becameActive ? 'SUPPLIER_ACTIVATED' : 'SUPPLIER_UPDATED',
                'customer' => $becameActive ? 'CUSTOMER_ACTIVATED' : 'CUSTOMER_UPDATED',
                default => $becameActive ? 'ACCOUNT_ACTIVATED' : 'ACCOUNT_UPDATED',
            };
            $this->audit->record($event, [
                'resource_type' => $partyType === 'vendor' ? 'supplier' : $partyType,
                'resource_ulid' => $party->ulid,
            ]);
        });

        return new PartyResource($party->refresh()->load('accountType'), $partyType);
    }

    public function destroy(Request $request, string $partyUlid): JsonResponse
    {
        [$party, $partyType] = $this->resolveParty($request, $partyUlid);
        $this->authorize('delete', $party);

        DB::transaction(function () use ($party, $partyType): void {
            $party->is_active = false;
            $party->updated_by = app(TenantContext::class)->userId();
            $party->save();

            $this->audit->record(
                match ($partyType) {
                    'vendor' => 'SUPPLIER_DEACTIVATED',
                    'customer' => 'CUSTOMER_DEACTIVATED',
                    default => 'ACCOUNT_DEACTIVATED',
                },
                [
                    'resource_type' => $partyType === 'vendor' ? 'supplier' : $partyType,
                    'resource_ulid' => $party->ulid,
                ],
            );
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }

    /**
     * @return array{0: Supplier|Customer|Account, 1: string}
     */
    private function resolveParty(Request $request, string $partyUlid): array
    {
        $type = strtolower(trim((string) $request->query('type', $request->input('party_type', ''))));
        if (! in_array($type, ['vendor', 'customer', 'account'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'party type (vendor|customer|account) is required.', 422);
        }

        $party = match ($type) {
            'vendor' => $this->catalog->supplier($partyUlid),
            'customer' => $this->catalog->customer($partyUlid),
            default => $this->catalog->account($partyUlid),
        };

        return [$party, $type];
    }

    private function authorizeCreate(string $partyType): void
    {
        match ($partyType) {
            'vendor' => $this->authorize('create', Supplier::class),
            'customer' => $this->authorize('create', Customer::class),
            'account' => $this->authorize('create', Account::class),
            default => throw new ApiException('VALIDATION_FAILED', 'Invalid party type.', 422),
        };
    }

    private function canViewVendors(): bool
    {
        return $this->userCan('suppliers.view')
            || $this->userCan('suppliers.manage')
            || $this->userCan('vendors.view');
    }

    private function canViewCustomers(): bool
    {
        return $this->userCan('customers.view') || $this->userCan('customers.manage');
    }

    private function canViewAccounts(): bool
    {
        return $this->userCan('accounts.view') || $this->userCan('accounts.manage');
    }

    private function userCan(string $permission): bool
    {
        return app(\App\Authz\PermissionService::class)->can($permission);
    }
}
