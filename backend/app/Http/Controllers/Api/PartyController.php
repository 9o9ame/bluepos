<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\StorePartyRequest;
use App\Http\Requests\Parties\UpdatePartyRequest;
use App\Http\Resources\PartyResource;
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
                ->orderBy('name')
                ->get()
                ->map(fn (Supplier $supplier) => (new PartyResource($supplier, 'vendor'))->resolve());
            $rows = $rows->concat($vendors);
        }

        if (in_array($type, ['all', 'customer'], true) && $this->canViewCustomers()) {
            $customers = Customer::query()
                ->forTenant($tenantContext->tenantId())
                ->orderBy('name')
                ->get()
                ->map(fn (Customer $customer) => (new PartyResource($customer, 'customer'))->resolve());
            $rows = $rows->concat($customers);
        }

        // account + salesman: no domain master in VCA-2 — empty contribution only.

        $sorted = $rows
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['name']), SORT_NATURAL)
            ->values()
            ->all();

        return response()->json(['data' => $sorted]);
    }

    public function store(StorePartyRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $partyType = (string) $request->validated('party_type');

        if ($partyType === 'vendor') {
            $this->authorize('create', Supplier::class);
        } else {
            $this->authorize('create', Customer::class);
        }

        $party = DB::transaction(function () use ($request, $tenantContext, $partyType): Supplier|Customer {
            $payload = [
                'tenant_id' => $tenantContext->tenantId(),
                ...$request->partyAttributes(),
                'is_active' => $request->boolean('is_active', true),
                'created_by' => $tenantContext->userId(),
            ];

            if ($partyType === 'vendor') {
                $party = Supplier::query()->create($payload);
                $this->audit->record('SUPPLIER_CREATED', [
                    'resource_type' => 'supplier',
                    'resource_ulid' => $party->ulid,
                ]);

                return $party;
            }

            $party = Customer::query()->create($payload);
            $this->audit->record('CUSTOMER_CREATED', [
                'resource_type' => 'customer',
                'resource_ulid' => $party->ulid,
            ]);

            return $party;
        });

        return (new PartyResource($party, $partyType))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $partyUlid): PartyResource
    {
        [$party, $partyType] = $this->resolveParty($request, $partyUlid);
        $this->authorize('view', $party);

        return new PartyResource($party, $partyType);
    }

    public function update(UpdatePartyRequest $request, string $partyUlid): PartyResource
    {
        $partyType = (string) $request->validated('party_type');
        $party = $partyType === 'vendor'
            ? $this->catalog->supplier($partyUlid)
            : $this->catalog->customer($partyUlid);

        $this->authorize('update', $party);

        DB::transaction(function () use ($party, $request, $partyType): void {
            $wasActive = (bool) $party->is_active;
            $party->fill($request->partyAttributes());
            $party->updated_by = app(TenantContext::class)->userId();
            $party->save();

            $becameActive = ! $wasActive && (bool) $party->is_active;
            if ($partyType === 'vendor') {
                $this->audit->record($becameActive ? 'SUPPLIER_ACTIVATED' : 'SUPPLIER_UPDATED', [
                    'resource_type' => 'supplier',
                    'resource_ulid' => $party->ulid,
                ]);
            } else {
                $this->audit->record($becameActive ? 'CUSTOMER_ACTIVATED' : 'CUSTOMER_UPDATED', [
                    'resource_type' => 'customer',
                    'resource_ulid' => $party->ulid,
                ]);
            }
        });

        return new PartyResource($party->refresh(), $partyType);
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
                $partyType === 'vendor' ? 'SUPPLIER_DEACTIVATED' : 'CUSTOMER_DEACTIVATED',
                [
                    'resource_type' => $partyType === 'vendor' ? 'supplier' : 'customer',
                    'resource_ulid' => $party->ulid,
                ],
            );
        });

        return response()->json(['ok' => true, 'archived' => true]);
    }

    /**
     * @return array{0: Supplier|Customer, 1: string}
     */
    private function resolveParty(Request $request, string $partyUlid): array
    {
        $type = strtolower(trim((string) $request->query('type', $request->input('party_type', ''))));
        if (! in_array($type, ['vendor', 'customer'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'party type (vendor|customer) is required.', 422);
        }

        $party = $type === 'vendor'
            ? $this->catalog->supplier($partyUlid)
            : $this->catalog->customer($partyUlid);

        return [$party, $type];
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

    private function userCan(string $permission): bool
    {
        return app(\App\Authz\PermissionService::class)->can($permission);
    }
}
