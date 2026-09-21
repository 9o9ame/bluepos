<?php

namespace App\Http\Middleware;

use App\Actions\Auth\EstablishAuthSessionAction;
use App\Authz\MembershipAccess;
use App\Enums\DeviceStatus;
use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AuthSessionRecord;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Warehouse;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MembershipAccess $membershipAccess,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new ApiException('UNAUTHORIZED', 'You are not authorized to perform this action.', 401);
        }

        $this->rejectClientTenancyOverrides($request);

        if ($user->status !== UserStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        $membershipId = $request->session()->get(EstablishAuthSessionAction::MEMBERSHIP_ID);

        $membershipQuery = Membership::query()
            ->with(['tenant', 'user'])
            ->where('user_id', $user->id);

        if (is_numeric($membershipId)) {
            $membership = (clone $membershipQuery)->where('id', (int) $membershipId)->first();
        } else {
            $membership = null;
        }

        $membership ??= $membershipQuery->orderByDesc('is_owner')->first();

        if (! $membership || ! $membership->tenant) {
            throw new ApiException('NO_MEMBERSHIP', 'You do not have access to a tenant.', 403);
        }

        if ($membership->status !== MembershipStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        if (! $this->entitlements->tenantIsLicensed($membership->tenant)) {
            throw new ApiException('TENANT_DISABLED', 'This tenant is not active.', 403);
        }

        $sessionVersion = (int) $request->session()->get(EstablishAuthSessionAction::SECURITY_VERSION, 0);
        if ($sessionVersion !== $membership->currentSecurityVersion()) {
            throw new ApiException('SESSION_REVOKED', 'This session is no longer valid.', 401);
        }

        $device = $this->resolveDevice($request, $membership);
        $this->assertAuthSessionRecord($request, $membership, $device);

        $branchId = $request->session()->get(EstablishAuthSessionAction::BRANCH_ID);
        $preferredBranchId = is_numeric($branchId) ? (int) $branchId : null;
        if ($device?->branch_id) {
            $preferredBranchId = (int) $device->branch_id;
        }
        $branch = $this->membershipAccess->resolveBranch($membership, $preferredBranchId);

        if (! $branch) {
            throw new ApiException('NO_BRANCH', 'No branch is available for this tenant.', 403);
        }

        if ($device?->branch_id && (int) $device->branch_id !== (int) $branch->id) {
            throw new ApiException('BRANCH_ACCESS_DENIED', 'This device is not assigned to an allowed branch.', 403);
        }

        $warehouseId = $request->session()->get(EstablishAuthSessionAction::WAREHOUSE_ID);
        $warehouse = null;

        if ($device?->warehouse_id) {
            $warehouse = Warehouse::query()
                ->forTenant((int) $membership->tenant_id)
                ->where('branch_id', $branch->id)
                ->whereKey($device->warehouse_id)
                ->first();
        }

        if (is_numeric($warehouseId) && $warehouse === null) {
            $warehouse = Warehouse::query()
                ->forTenant((int) $membership->tenant_id)
                ->where('branch_id', $branch->id)
                ->where('id', (int) $warehouseId)
                ->first();
        }

        $warehouse ??= Warehouse::query()
            ->forTenant((int) $membership->tenant_id)
            ->where('branch_id', $branch->id)
            ->orderByDesc('is_default')
            ->first();

        if (! $warehouse) {
            throw new ApiException('NO_WAREHOUSE', 'No warehouse is available for this branch.', 403);
        }

        $this->tenantContext->hydrate($user, $membership->tenant, $membership, $branch, $warehouse, $device);

        $request->session()->put([
            EstablishAuthSessionAction::TENANT_ID => $membership->tenant_id,
            EstablishAuthSessionAction::MEMBERSHIP_ID => $membership->id,
            EstablishAuthSessionAction::BRANCH_ID => $branch->id,
            EstablishAuthSessionAction::WAREHOUSE_ID => $warehouse->id,
            EstablishAuthSessionAction::DEVICE_ID => $device?->id,
        ]);

        if ($this->requiresPasswordChange($request, $user)) {
            throw new ApiException('PASSWORD_CHANGE_REQUIRED', 'You must change your password before continuing.', 403);
        }

        return $next($request);
    }

    private function resolveDevice(Request $request, Membership $membership): ?Device
    {
        $deviceId = $request->session()->get(EstablishAuthSessionAction::DEVICE_ID);
        if (! is_numeric($deviceId)) {
            return null;
        }

        $device = Device::query()->whereKey((int) $deviceId)->first();
        if (! $device) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        if ($device->tenant_id !== null && (int) $device->tenant_id !== (int) $membership->tenant_id) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        if ($device->status === DeviceStatus::Revoked || $device->status === DeviceStatus::Disabled) {
            throw new ApiException('DEVICE_REVOKED', 'This device has been revoked.', 403);
        }

        if (! $device->isActive()) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        $device->last_seen_at = now();
        $device->save();

        return $device;
    }

    private function assertAuthSessionRecord(Request $request, Membership $membership, ?Device $device): void
    {
        $ulid = $request->session()->get(EstablishAuthSessionAction::AUTH_SESSION_ULID);
        if (! is_string($ulid) || $ulid === '') {
            return;
        }

        $record = AuthSessionRecord::query()->where('ulid', $ulid)->first();
        if (! $record || $record->isRevoked() || (int) $record->membership_id !== (int) $membership->id) {
            throw new ApiException('SESSION_REVOKED', 'This session is no longer valid.', 401);
        }

        if ($device && $record->device_id && (int) $record->device_id !== (int) $device->id) {
            throw new ApiException('SESSION_REVOKED', 'This session is no longer valid.', 401);
        }

        $record->last_seen_at = now();
        $record->save();
    }

    private function requiresPasswordChange(Request $request, mixed $user): bool
    {
        if (! $user->must_change_password) {
            return false;
        }

        $path = '/'.$request->path();

        return ! in_array($path, [
            '/api/auth/me',
            '/api/auth/logout',
            '/api/auth/change-password',
        ], true);
    }

    private function rejectClientTenancyOverrides(Request $request): void
    {
        foreach (['tenant_id', 'branch_id', 'warehouse_id', 'membership_id', 'device_id'] as $key) {
            $request->request->remove($key);
            $request->query->remove($key);
        }
    }
}
