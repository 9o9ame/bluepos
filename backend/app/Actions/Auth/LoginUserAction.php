<?php

namespace App\Actions\Auth;

use App\Auth\AuthenticatedSession;
use App\Authz\MembershipAccess;
use App\Enums\DeviceStatus;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\AuditLogger;
use App\Security\DeviceCredentialService;
use App\Security\DeviceNetworkTrust;
use App\Security\EmployeeDeviceApprovalNotifier;
use App\Security\PrivilegedAccess;
use App\Security\TenantEntitlementService;
use App\Support\IdentityNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginUserAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DeviceCredentialService $devices,
        private readonly PrivilegedAccess $privilegedAccess,
        private readonly MembershipAccess $membershipAccess,
        private readonly TenantEntitlementService $entitlements,
        private readonly IssueTenantMfaChallengeAction $issueMfa,
    ) {}

    public function execute(Request $request, string $tenantCode, string $username, string $password): AuthenticatedSession
    {
        $code = IdentityNormalizer::tenantCode($tenantCode);
        $name = IdentityNormalizer::username($username);

        $fail = function (?Tenant $knownTenant = null) use ($request, $code, $name): never {
            $this->audit->record('LOGIN_FAILURE', [
                'tenant_id' => $knownTenant?->id,
                'tenant_code_hash' => hash('sha256', $code),
                'username_hash' => hash('sha256', $name),
            ], $request);

            throw new ApiException('INVALID_CREDENTIALS', 'Invalid tenant code, username, or password.', 401);
        };

        $tenant = Tenant::query()->where('code', $code)->first();
        if (! $tenant) {
            $fail();
        }

        $this->entitlements->assertOperational($tenant);

        $membership = Membership::query()
            ->with('user', 'tenant')
            ->where('tenant_id', $tenant->id)
            ->where('username', $name)
            ->first();

        if (! $membership || ! $membership->user) {
            $fail($tenant);
        }

        $user = $membership->user;

        if (! Hash::check($password, $user->password)) {
            $fail($tenant);
        }

        if ($user->status !== UserStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        if ($membership->status !== MembershipStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Trial], true)) {
            throw new ApiException('TENANT_DISABLED', 'This tenant is not active.', 403);
        }

        $device = $this->devices->locate($request);
        try {
            $device = $this->assertDevice($request, $membership, $device);
        } catch (ApiException $error) {
            $this->audit->record('LOGIN_BLOCKED', [
                'tenant_id' => $tenant->id,
                'actor_user_id' => $user->id,
                'actor_membership_id' => $membership->id,
                'device_id' => $device?->id,
                'reason' => $error->errorKey,
            ], $request);
            throw $error;
        }

        $branch = $this->membershipAccess->resolveBranch($membership, $device->branch_id ? (int) $device->branch_id : null);
        if (! $branch) {
            throw new ApiException('BRANCH_ACCESS_DENIED', 'No branch is available for this account.', 403);
        }

        if ($device->branch_id && (int) $device->branch_id !== (int) $branch->id) {
            throw new ApiException('BRANCH_ACCESS_DENIED', 'This device is not assigned to an allowed branch.', 403);
        }

        $warehouse = null;
        if ($device->warehouse_id) {
            $warehouse = Warehouse::query()
                ->forTenant((int) $tenant->id)
                ->where('branch_id', $branch->id)
                ->whereKey($device->warehouse_id)
                ->first();
        }
        $warehouse ??= Warehouse::query()
            ->forTenant((int) $tenant->id)
            ->where('branch_id', $branch->id)
            ->orderByDesc('is_default')
            ->first();

        if (! $warehouse) {
            throw new ApiException('NO_WAREHOUSE', 'No warehouse is available for this branch.', 403);
        }

        $device->last_seen_at = now();
        $device->save();

        return new AuthenticatedSession(
            user: $user,
            tenant: $tenant,
            membership: $membership,
            branch: $branch,
            warehouse: $warehouse,
            device: $device,
        );
    }

    private function assertDevice(Request $request, Membership $membership, ?Device $device): Device
    {
        $privileged = $this->privilegedAccess->requiresMfaOnUnknownDevice($membership);

        if ($device === null) {
            $device = $this->pendingDevice($membership);
            if ($membership->hasOwnerAuthority()) {
                $this->requireMfa($request, $membership, $device);
            }

            $this->recordStaffAccessRequest($request, $membership, $device);
            $this->audit->record('LOGIN_FAILURE', [
                'actor_membership_id' => $membership->id,
                'tenant_id' => $membership->tenant_id,
                'device_id' => $device->id,
                'reason' => 'device_missing',
            ], $request);

            throw new ApiException(
                'DEVICE_NOT_APPROVED',
                'Your tenant owner must approve this device and network.',
                403,
                ['approval_status' => $this->staffApprovalStatus($request, $membership, $device)]
            );
        }

        if ($device->tenant_id !== null && (int) $device->tenant_id !== (int) $membership->tenant_id) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        if ($device->status === DeviceStatus::Revoked) {
            throw new ApiException('DEVICE_REVOKED', 'This device has been revoked.', 403);
        }

        if ($device->status === DeviceStatus::Disabled) {
            throw new ApiException('DEVICE_REVOKED', 'This device has been revoked.', 403);
        }

        if ($device->status === DeviceStatus::Pending) {
            if ($membership->hasOwnerAuthority()) {
                $this->requireMfa($request, $membership, $device);
            }
            $this->recordStaffAccessRequest($request, $membership, $device);

            throw new ApiException(
                'DEVICE_NOT_APPROVED',
                'Your tenant administrator must approve this device and network.',
                403,
                ['approval_status' => $this->staffApprovalStatus($request, $membership, $device)]
            );
        }

        if (! $device->isActive()) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        if (! $membership->hasOwnerAuthority()) {
            $network = app(DeviceNetworkTrust::class)->fingerprint($request);
            $hasManagedRequests = DB::table('device_access_requests')
                ->where('tenant_id', $membership->tenant_id)
                ->where('device_id', $device->id)->exists();

            // Browser devices always require member/network approval; pre-existing POS terminals
            // without managed requests remain grandfathered until an explicit migration.
            if ($hasManagedRequests || $device->device_type === 'browser') {
                $approved = $network !== null && DB::table('device_access_requests')
                    ->where('tenant_id', $membership->tenant_id)
                    ->where('device_id', $device->id)
                    ->where('membership_id', $membership->id)
                    ->where('network_hash', $network)
                    ->where('status', 'approved')->exists();

                if (! $approved) {
                    $this->recordStaffAccessRequest($request, $membership, $device);
                    throw new ApiException(
                        'DEVICE_NOT_APPROVED',
                        'Your tenant administrator must approve this device and network.',
                        403,
                        ['approval_status' => $this->staffApprovalStatus($request, $membership, $device)]
                    );
                }
            }
        }

        if ($privileged && (! $device->isTrusted() || ($membership->hasOwnerAuthority() && app(DeviceNetworkTrust::class)->requiresReverification($device, $request)))) {
            $this->requireMfa($request, $membership, $device);
        }

        return $device;
    }

    private function recordStaffAccessRequest(Request $request, Membership $membership, Device $device): void
    {
        if ($membership->hasOwnerAuthority() || ! in_array($device->status, [DeviceStatus::Pending, DeviceStatus::Active], true)) {
            return;
        }

        $network = app(DeviceNetworkTrust::class)->fingerprint($request);
        // Do not persist unidentifiable network approval requests.
        if ($network === null) {
            return;
        }

        $created = DB::transaction(function () use ($membership, $device, $network, $request): bool {
            // Lock the tenant to serialize retries with owner review and avoid duplicate alerts.
            Tenant::query()->whereKey($membership->tenant_id)->lockForUpdate()->firstOrFail();

            $existing = DB::table('device_access_requests')
                ->where('tenant_id', $membership->tenant_id)
                ->where('membership_id', $membership->id)
                ->where('device_id', $device->id)
                ->where('network_hash', $network)
                ->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->status !== 'rejected'
                    || $existing->reviewed_at === null
                    || now()->lessThan(\Illuminate\Support\Carbon::parse($existing->reviewed_at)->addDay())) {
                    return false;
                }

                DB::table('device_access_requests')->where('id', $existing->id)->update([
                    'status' => 'pending',
                    'request_ip' => $request->ip(),
                    'request_user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 180, ''),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'updated_at' => now(),
                ]);

                return true;
            }

            return DB::table('device_access_requests')->insertOrIgnore([
                'ulid' => (string) Str::ulid(),
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'device_id' => $device->id,
                'network_hash' => $network,
                'request_ip' => $request->ip(),
                'request_user_agent' => \Illuminate\Support\Str::limit((string) $request->userAgent(), 180, ''),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]) > 0;
        });

        if ($created) {
            app(EmployeeDeviceApprovalNotifier::class)->notifyOwners($membership, $device->name, $request->ip());
            $this->audit->record('DEVICE_ACCESS_REQUESTED', [
                'tenant_id' => $membership->tenant_id,
                'actor_user_id' => $membership->user_id,
                'actor_membership_id' => $membership->id,
                'device_id' => $device->id,
                'resource_type' => 'device',
                'resource_ulid' => $device->ulid,
            ], $request);
        }
    }

    private function staffApprovalStatus(Request $request, Membership $membership, Device $device): string
    {
        $network = app(DeviceNetworkTrust::class)->fingerprint($request);
        if ($network === null) {
            return 'unavailable';
        }

        return DB::table('device_access_requests')
            ->where('tenant_id', $membership->tenant_id)
            ->where('membership_id', $membership->id)
            ->where('device_id', $device->id)
            ->where('network_hash', $network)
            ->value('status') ?? 'unavailable';
    }

    private function pendingDevice(Membership $membership): Device
    {
        $device = Device::query()->create([
            'tenant_id' => $membership->tenant_id,
            'name' => 'Pending device',
            'device_type' => 'browser',
            'status' => DeviceStatus::Pending,
            'credential_hash' => Hash::make(bin2hex(random_bytes(24))),
            'registered_at' => now(),
        ]);
        $credential = app(DeviceCredentialService::class)->issue($device);
        Cookie::queue(app(DeviceCredentialService::class)->cookie($credential));

        return $device->fresh() ?? $device;
    }

    private function requireMfa(Request $request, Membership $membership, Device $device): never
    {
        $this->issueMfa->execute($request, $membership, $device);
    }
}
