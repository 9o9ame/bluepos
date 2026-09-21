<?php

namespace App\Actions\Auth;

use App\Auth\AuthenticatedSession;
use App\Authz\MembershipAccess;
use App\Enums\DeviceStatus;
use App\Enums\MembershipStatus;
use App\Enums\MfaMethod;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Membership;
use App\Models\MfaChallenge;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Notifications\SecurityCodeNotification;
use App\Security\AuditLogger;
use App\Security\DeviceCredentialService;
use App\Security\PrivilegedAccess;
use App\Security\TenantEntitlementService;
use App\Support\IdentityNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class LoginUserAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DeviceCredentialService $devices,
        private readonly PrivilegedAccess $privilegedAccess,
        private readonly MembershipAccess $membershipAccess,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    public function execute(Request $request, string $tenantCode, string $username, string $password): AuthenticatedSession
    {
        $code = IdentityNormalizer::tenantCode($tenantCode);
        $name = IdentityNormalizer::username($username);

        $fail = function () use ($request, $code, $name): never {
            $this->audit->record('LOGIN_FAILURE', [
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
            $fail();
        }

        $user = $membership->user;

        if (! Hash::check($password, $user->password)) {
            $fail();
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
        $device = $this->assertDevice($request, $membership, $device);

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
            if ($privileged) {
                $this->requireMfa($request, $membership, $device);
            }

            $this->audit->record('LOGIN_FAILURE', [
                'actor_membership_id' => $membership->id,
                'tenant_id' => $membership->tenant_id,
                'device_id' => $device->id,
                'reason' => 'device_missing',
            ], $request);

            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
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
            if ($privileged) {
                $this->requireMfa($request, $membership, $device);
            }

            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        if (! $device->isActive()) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        if ($privileged && ! $device->isTrusted()) {
            $this->requireMfa($request, $membership, $device);
        }

        return $device;
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
        $code = (string) random_int(100000, 999999);

        $challenge = MfaChallenge::query()->create([
            'tenant_id' => $membership->tenant_id,
            'user_id' => $membership->user_id,
            'membership_id' => $membership->id,
            'device_id' => $device->id,
            'method' => MfaMethod::EmailOtp,
            'purpose' => 'login',
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        $address = $membership->user?->recoveryAddress();
        if ($address) {
            Notification::route('mail', $address)
                ->notify(new SecurityCodeNotification($code, 'admin login'));
        }

        $this->audit->record('MFA_CHALLENGE', [
            'tenant_id' => $membership->tenant_id,
            'actor_user_id' => $membership->user_id,
            'actor_membership_id' => $membership->id,
            'device_id' => $device->id,
            'resource_type' => 'mfa_challenge',
            'resource_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
        ], $request);

        $masked = $this->mask($address);

        throw new ApiException('MFA_REQUIRED', 'Additional verification is required for this device.', 403, [
            'challenge_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
            'recovery_hint' => $masked,
        ]);
    }

    private function mask(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $keep = substr($local, 0, 1);

        return $keep.'***@'.$domain;
    }
}
