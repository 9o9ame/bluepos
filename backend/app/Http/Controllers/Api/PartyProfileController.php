<?php

namespace App\Http\Controllers\Api;

use App\Actions\Parties\UpsertPartyProfileAction;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\UpsertPartyProfileRequest;
use App\Http\Resources\PartyProfileResource;
use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartyProfileController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenantContext,
        PermissionService $permissions,
    ): JsonResponse {
        $type = strtolower(trim((string) $request->query('type', 'all')));
        if (! in_array($type, ['all', 'vendor', 'customer', 'salesman'], true)) {
            throw new ApiException('VALIDATION_FAILED', 'Invalid party profile type filter.', 422);
        }

        $this->authorizeViewType($type, $permissions);

        $allowedTypes = $this->allowedViewTypes($permissions);

        $profiles = PartyProfile::query()
            ->forTenant($tenantContext->tenantId())
            ->with(['types', 'supplier.accountType', 'customer.accountType'])
            ->when(
                $type !== 'all',
                fn ($q) => $q->whereHas('types', fn ($t) => $t->where('type', $type)),
                fn ($q) => $q->whereHas('types', fn ($t) => $t->whereIn('type', $allowedTypes)),
            )
            ->orderBy('name')
            ->get();

        $data = $profiles->map(function (PartyProfile $profile) use ($type, $permissions): array {
            $primary = $type === 'all'
                ? $this->primaryTypeFor($profile, $permissions)
                : $type;

            return (new PartyProfileResource($profile, $primary))->resolve();
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function show(
        string $profileUlid,
        TenantContext $tenantContext,
        PermissionService $permissions,
    ): PartyProfileResource {
        $profile = $this->find($profileUlid, $tenantContext);
        $this->authorizeExistingTypes($profile, $permissions, 'view');

        return new PartyProfileResource($profile, $this->primaryTypeFor($profile, $permissions));
    }

    public function store(
        UpsertPartyProfileRequest $request,
        UpsertPartyProfileAction $upsert,
        PermissionService $permissions,
    ): JsonResponse {
        $types = $request->validated('party_types');
        $this->authorizeRequestedTypes($types, $permissions, 'create');

        $profile = $upsert->execute(
            null,
            $request->profileAttributes(),
            $types,
            $request->validated()['vendor_account_type_ulid'] ?? null,
            $request->validated()['customer_account_type_ulid'] ?? null,
        );

        return (new PartyProfileResource($profile, (string) $request->validated('primary_type')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpsertPartyProfileRequest $request,
        string $profileUlid,
        TenantContext $tenantContext,
        UpsertPartyProfileAction $upsert,
        PermissionService $permissions,
    ): PartyProfileResource {
        $profile = $this->find($profileUlid, $tenantContext);
        $current = $profile->types->pluck('type')->all();
        $requested = $request->validated('party_types');
        $this->authorizeRequestedTypes(array_values(array_unique([...$current, ...$requested])), $permissions, 'edit');

        $profile = $upsert->execute(
            $profile,
            $request->profileAttributes(),
            $requested,
            $request->validated('vendor_account_type_ulid'),
            $request->validated('customer_account_type_ulid'),
        );

        return new PartyProfileResource($profile, (string) $request->validated('primary_type'));
    }

    private function find(string $ulid, TenantContext $tenantContext): PartyProfile
    {
        $profile = PartyProfile::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->with(['types', 'supplier.accountType', 'customer.accountType'])
            ->first();

        if (! $profile) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $profile;
    }

    private function authorizeViewType(string $type, PermissionService $permissions): void
    {
        if ($type === 'vendor') {
            $this->authorize('viewAny', Supplier::class);
            return;
        }
        if ($type === 'customer') {
            $this->authorize('viewAny', Customer::class);
            return;
        }
        if ($type === 'salesman') {
            abort_unless(
                $permissions->can('customers.view') || $permissions->can('suppliers.view') || $permissions->can('sales.create'),
                403
            );
            return;
        }

        abort_unless(
            $permissions->can('customers.view') || $permissions->can('suppliers.view') || $permissions->can('sales.create'),
            403
        );
    }

    /**
     * @param list<string> $types
     */
    private function authorizeRequestedTypes(array $types, PermissionService $permissions, string $ability): void
    {
        foreach ($types as $type) {
            if ($type === 'vendor') {
                $key = $ability === 'create' ? 'suppliers.create' : ($ability === 'view' ? 'suppliers.view' : 'suppliers.edit');
                abort_unless($permissions->can($key) || $permissions->can('suppliers.manage'), 403);
                continue;
            }

            if ($type === 'customer') {
                $key = $ability === 'create' ? 'customers.create' : ($ability === 'view' ? 'customers.view' : 'customers.edit');
                abort_unless($permissions->can($key) || $permissions->can('customers.manage'), 403);
                continue;
            }

            if ($type === 'salesman') {
                if ($ability === 'view') {
                    abort_unless(
                        $permissions->can('sales.create')
                            || $permissions->can('customers.view')
                            || $permissions->can('suppliers.view')
                            || $permissions->can('customers.manage')
                            || $permissions->can('suppliers.manage'),
                        403
                    );
                    continue;
                }

                abort_unless(
                    $permissions->can('customers.manage')
                        || $permissions->can('suppliers.manage')
                        || $permissions->can('customers.create')
                        || $permissions->can('suppliers.create'),
                    403
                );
            }
        }
    }

    private function authorizeExistingTypes(PartyProfile $profile, PermissionService $permissions, string $ability): void
    {
        $this->authorizeRequestedTypes($profile->types->pluck('type')->all(), $permissions, $ability);
    }

    /**
     * @return list<string>
     */
    private function allowedViewTypes(PermissionService $permissions): array
    {
        $types = [];

        if ($permissions->can('customers.view') || $permissions->can('customers.manage')) {
            $types[] = 'customer';
        }
        if ($permissions->can('suppliers.view') || $permissions->can('suppliers.manage')) {
            $types[] = 'vendor';
        }
        if (
            $permissions->can('sales.create')
            || $permissions->can('customers.view')
            || $permissions->can('suppliers.view')
        ) {
            $types[] = 'salesman';
        }

        return array_values(array_unique($types));
    }

    private function primaryTypeFor(PartyProfile $profile, PermissionService $permissions): string
    {
        $types = $profile->types->pluck('type');

        if ($types->contains('customer') && ($permissions->can('customers.view') || $permissions->can('customers.manage'))) {
            return 'customer';
        }
        if ($types->contains('vendor') && ($permissions->can('suppliers.view') || $permissions->can('suppliers.manage'))) {
            return 'vendor';
        }
        if ($types->contains('salesman')) {
            return 'salesman';
        }

        throw new ApiException('FORBIDDEN', 'You are not allowed to view this party.', 403);
    }
}
