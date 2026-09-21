<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\Device;
use App\Models\User;
use App\Tenancy\TenantContext;

class DevicePolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can('devices.view');
    }

    public function view(User $user, Device $device): bool
    {
        return $this->permissions->can('devices.view') && $this->sameTenant($device);
    }

    public function approve(User $user, Device $device): bool
    {
        return $this->permissions->can('devices.approve') && $this->sameTenantOrUnassigned($device);
    }

    public function revoke(User $user, Device $device): bool
    {
        return $this->permissions->can('devices.revoke') && $this->sameTenant($device);
    }

    public function assignBranch(User $user, Device $device): bool
    {
        return $this->permissions->can('devices.assign_branch') && $this->sameTenant($device);
    }

    public function assignWarehouse(User $user, Device $device): bool
    {
        return $this->permissions->can('devices.assign_warehouse') && $this->sameTenant($device);
    }

    private function sameTenant(Device $device): bool
    {
        return $this->tenantContext->hasTenant()
            && $device->tenant_id !== null
            && (int) $device->tenant_id === $this->tenantContext->tenantId();
    }

    private function sameTenantOrUnassigned(Device $device): bool
    {
        if (! $this->tenantContext->hasTenant()) {
            return false;
        }

        return $device->tenant_id === null
            || (int) $device->tenant_id === $this->tenantContext->tenantId();
    }
}
