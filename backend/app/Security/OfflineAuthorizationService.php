<?php

namespace App\Security;

use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Membership;
use App\Models\OfflineAuthorizationLease;
use Illuminate\Support\Facades\DB;

class OfflineAuthorizationService
{
    public function issue(
        Membership $membership,
        Device $device,
        Branch $branch,
        array $permissionKeys,
        int $hours = 8,
    ): OfflineAuthorizationLease {
        if ($membership->status !== MembershipStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        $tenant = $membership->tenant()->first() ?? $membership->tenant;
        if ($tenant) {
            app(TenantEntitlementService::class)->assertOperational($tenant);
        }

        if (! $device->isActive() || (int) $device->tenant_id !== (int) $membership->tenant_id) {
            throw new ApiException('DEVICE_NOT_APPROVED', 'This device is not approved. Ask your administrator to approve it.', 403);
        }

        return DB::transaction(function () use ($membership, $device, $branch, $permissionKeys, $hours): OfflineAuthorizationLease {
            // Serialize issuance with tenant device access reviews and device revocation.
            $lockedTenant = \App\Models\Tenant::query()->whereKey($membership->tenant_id)->lockForUpdate()->firstOrFail();
            app(TenantEntitlementService::class)->assertOperational($lockedTenant);
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $membership = Membership::query()->with(['user', 'tenant'])
                ->where('tenant_id', $membership->tenant_id)
                ->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($membership->status !== MembershipStatus::Active
                || ! $device->isActive()
                || (int) $device->tenant_id !== (int) $membership->tenant_id
                || (int) $branch->tenant_id !== (int) $membership->tenant_id
                || ($device->branch_id && (int) $device->branch_id !== (int) $branch->id)) {
                throw new ApiException('OFFLINE_AUTH_EXPIRED', 'Offline authorization is not available for this account or device.', 403);
            }

            $issuedAt = now();
            $expiresAt = $issuedAt->copy()->addHours($hours);
            $lease = OfflineAuthorizationLease::query()->create([
                'tenant_id' => $membership->tenant_id,
                'user_id' => $membership->user_id,
                'membership_id' => $membership->id,
                'device_id' => $device->id,
                'branch_id' => $branch->id,
                'security_version' => $membership->currentSecurityVersion(),
                'permission_snapshot' => array_values($permissionKeys),
                'signature' => 'pending',
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ]);

            $lease->signature = $this->sign($lease);
            $lease->save();

            app(AuditLogger::class)->record('OFFLINE_LEASE_ISSUED', [
                'tenant_id' => $membership->tenant_id,
                'resource_type' => 'offline_lease',
                'resource_ulid' => $lease->ulid,
            ]);

            return $lease->fresh() ?? $lease;
        });
    }

    public function assertValid(OfflineAuthorizationLease $lease, Device $device, Membership $membership): void
    {
        // Never trust previously loaded models when enforcing a revocation.
        $lease = OfflineAuthorizationLease::query()->whereKey($lease->id)->first();
        $device = Device::query()->whereKey($device->id)->first();
        $membership = Membership::query()->with(['user', 'tenant'])->whereKey($membership->id)->first();

        if (! $lease || ! $device || ! $membership) {
            throw new ApiException('OFFLINE_AUTH_EXPIRED', 'Offline authorization has expired. Reconnect to continue.', 403);
        }

        if ($lease->isExpired() || ! hash_equals($lease->signature, $this->sign($lease))) {
            app(AuditLogger::class)->record('OFFLINE_LEASE_REJECTED', [
                'resource_type' => 'offline_lease',
                'resource_ulid' => $lease->ulid,
            ]);
            throw new ApiException('OFFLINE_AUTH_EXPIRED', 'Offline authorization has expired. Reconnect to continue.', 403);
        }

        if ($membership->status !== MembershipStatus::Active
            || ! $device->isActive()
            || (int) $device->tenant_id !== (int) $membership->tenant_id
            || (int) $lease->tenant_id !== (int) $membership->tenant_id
            || (int) $lease->security_version !== $membership->currentSecurityVersion()) {
            throw new ApiException('OFFLINE_AUTH_EXPIRED', 'Offline authorization has expired. Reconnect to continue.', 403);
        }

        if ((int) $lease->device_id !== (int) $device->id || (int) $lease->membership_id !== (int) $membership->id) {
            throw new ApiException('OFFLINE_AUTH_EXPIRED', 'Offline authorization has expired. Reconnect to continue.', 403);
        }
    }

    private function sign(OfflineAuthorizationLease $lease): string
    {
        $payload = implode('|', [
            $lease->ulid,
            $lease->tenant_id,
            $lease->membership_id,
            $lease->device_id,
            $lease->branch_id,
            $lease->security_version,
            $lease->issued_at?->toIso8601String(),
            $lease->expires_at?->toIso8601String(),
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
