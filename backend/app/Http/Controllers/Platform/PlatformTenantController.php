<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ActivateTenantAction;
use App\Actions\Platform\AssignTenantSubscriptionAction;
use App\Actions\Platform\CreatePlatformTenantAction;
use App\Actions\Platform\ResetTenantAdminAccessAction;
use App\Actions\Platform\SuspendTenantAction;
use App\Actions\Platform\UpsertTenantFeatureOverrideAction;
use App\Actions\Platform\UpsertTenantLimitOverrideAction;
use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\AssignTenantSubscriptionRequest;
use App\Http\Requests\Platform\StorePlatformTenantRequest;
use App\Http\Requests\Platform\TenantFeatureOverrideRequest;
use App\Http\Requests\Platform\TenantLimitOverrideRequest;
use App\Http\Requests\Platform\UpdatePlatformTenantRequest;
use App\Http\Resources\Platform\PlatformTenantResource;
use App\Models\Tenant;
use App\Platform\FeatureCatalogue;
use App\Platform\LimitCatalogue;
use App\Security\TenantEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformTenantController extends Controller
{
    public function index(Request $request): mixed
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = Tenant::query()
            ->with(['subscription.plan'])
            ->withCount(['memberships', 'branches', 'warehouses'])
            ->withCount([
                'devices as active_devices_count' => fn ($devices) => $devices->where('status', DeviceStatus::Active),
            ])
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)
                    ->orWhere('code', 'ilike', $term)
                    ->orWhere('legal_name', 'ilike', $term);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return PlatformTenantResource::collection($query->paginate($perPage));
    }

    public function store(StorePlatformTenantRequest $request, CreatePlatformTenantAction $create): JsonResponse
    {
        $result = $create->execute($request->validated());
        $payload = (new PlatformTenantResource($result['tenant']))->resolve();
        $payload['initial_admin'] = [
            'username' => $result['username'],
            'must_change_password' => true,
            'temporary_password' => $result['temporary_password'],
        ];

        return response()->json($payload, 201);
    }

    public function show(string $tenantUlid): PlatformTenantResource
    {
        $tenant = $this->findTenant($tenantUlid);

        $tenant->load([
            'subscription.plan.features',
            'subscription.plan.limits',
            'featureOverrides',
            'limitOverrides',
            'memberships.user',
            'memberships.roles',
        ]);
        $tenant->loadCount(['memberships', 'branches', 'warehouses']);
        $tenant->loadCount([
            'devices as active_devices_count' => fn ($devices) => $devices->where('status', DeviceStatus::Active),
        ]);

        return new PlatformTenantResource($tenant);
    }

    public function update(UpdatePlatformTenantRequest $request, string $tenantUlid): PlatformTenantResource
    {
        $tenant = $this->findTenant($tenantUlid);
        $data = $request->validated();
        if (isset($data['tenant_name'])) {
            $tenant->name = $data['tenant_name'];
        }
        if (array_key_exists('legal_name', $data)) {
            $tenant->legal_name = $data['legal_name'];
        }
        if (isset($data['timezone'])) {
            $tenant->timezone = $data['timezone'];
        }
        if (isset($data['currency_code'])) {
            $tenant->currency_code = strtoupper($data['currency_code']);
        }
        $tenant->save();

        return new PlatformTenantResource($tenant->fresh(['subscription.plan']) ?? $tenant);
    }

    public function activate(string $tenantUlid, ActivateTenantAction $activate): PlatformTenantResource
    {
        return new PlatformTenantResource($activate->execute($this->findTenant($tenantUlid))->load('subscription.plan'));
    }

    public function suspend(Request $request, string $tenantUlid, SuspendTenantAction $suspend): PlatformTenantResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return new PlatformTenantResource(
            $suspend->execute($this->findTenant($tenantUlid), $data['reason'])->load('subscription.plan')
        );
    }

    public function assignSubscription(
        AssignTenantSubscriptionRequest $request,
        string $tenantUlid,
        AssignTenantSubscriptionAction $assign,
    ): JsonResponse {
        $subscription = $assign->execute($this->findTenant($tenantUlid), $request->validated());

        return response()->json([
            'plan' => [
                'ulid' => $subscription->plan?->ulid,
                'code' => $subscription->plan?->code,
                'name' => $subscription->plan?->name,
            ],
            'status' => $subscription->status->value,
            'trial_starts_at' => $subscription->trial_starts_at?->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'starts_at' => $subscription->starts_at?->toISOString(),
            'ends_at' => $subscription->ends_at?->toISOString(),
            'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
        ]);
    }

    public function upsertFeature(
        TenantFeatureOverrideRequest $request,
        string $tenantUlid,
        string $featureKey,
        UpsertTenantFeatureOverrideAction $upsert,
        TenantEntitlementService $entitlements,
    ): JsonResponse {
        $tenant = $this->findTenant($tenantUlid);
        $override = $upsert->execute(
            $tenant,
            $featureKey,
            (bool) $request->boolean('enabled'),
            $request->validated('reason'),
        );

        return response()->json([
            'feature_key' => $featureKey,
            'override' => (bool) $override->enabled,
            'effective' => $entitlements->hasFeature($tenant->fresh() ?? $tenant, $featureKey),
            'reason' => $override->reason,
        ]);
    }

    public function deleteFeature(
        Request $request,
        string $tenantUlid,
        string $featureKey,
        UpsertTenantFeatureOverrideAction $upsert,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if (! FeatureCatalogue::isValid($featureKey)) {
            throw new ApiException('VALIDATION_ERROR', 'Unknown feature key.', 422);
        }
        $upsert->clear($this->findTenant($tenantUlid), $featureKey, $data['reason']);

        return response()->json(['ok' => true]);
    }

    public function upsertLimit(
        TenantLimitOverrideRequest $request,
        string $tenantUlid,
        string $limitKey,
        UpsertTenantLimitOverrideAction $upsert,
        TenantEntitlementService $entitlements,
    ): JsonResponse {
        $tenant = $this->findTenant($tenantUlid);
        $override = $upsert->execute(
            $tenant,
            $limitKey,
            $request->validated('value'),
            $request->validated('reason'),
        );
        $usage = $entitlements->usage($tenant, $limitKey);
        $effective = $entitlements->limit($tenant->fresh() ?? $tenant, $limitKey);
        $warning = $effective !== null && $usage > $effective
            ? 'Current usage exceeds the new limit. Existing resources are not deleted.'
            : null;

        return response()->json([
            'limit_key' => $limitKey,
            'override' => $override->value,
            'effective' => $effective,
            'usage' => $usage,
            'warning' => $warning,
        ]);
    }

    public function deleteLimit(
        Request $request,
        string $tenantUlid,
        string $limitKey,
        UpsertTenantLimitOverrideAction $upsert,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if (! LimitCatalogue::isValid($limitKey)) {
            throw new ApiException('VALIDATION_ERROR', 'Unknown limit key.', 422);
        }
        $upsert->clear($this->findTenant($tenantUlid), $limitKey, $data['reason']);

        return response()->json(['ok' => true]);
    }

    public function resetAdmin(
        Request $request,
        string $tenantUlid,
        ResetTenantAdminAccessAction $reset,
    ): JsonResponse {
        $data = $request->validate([
            'membership_ulid' => ['nullable', 'string', 'size:26'],
        ]);
        $result = $reset->execute($this->findTenant($tenantUlid), $data['membership_ulid'] ?? null);

        return response()->json([
            'membership_ulid' => $result['membership']->ulid,
            'username' => $result['membership']->username,
            'must_change_password' => true,
            'temporary_password' => $result['temporary_password'],
        ]);
    }

    private function findTenant(string $tenantUlid): Tenant
    {
        $tenant = Tenant::query()->where('ulid', $tenantUlid)->first();
        if (! $tenant) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $tenant;
    }
}
