<?php

namespace App\Tenancy;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use RuntimeException;

class TenantContext
{
    private ?User $user = null;

    private ?Tenant $tenant = null;

    private ?Membership $membership = null;

    private ?Branch $branch = null;

    private ?Warehouse $warehouse = null;

    private ?Device $device = null;

    public function clear(): void
    {
        $this->user = null;
        $this->tenant = null;
        $this->membership = null;
        $this->branch = null;
        $this->warehouse = null;
        $this->device = null;
    }

    public function hydrate(
        User $user,
        Tenant $tenant,
        Membership $membership,
        Branch $branch,
        Warehouse $warehouse,
        ?Device $device = null,
    ): void {
        $this->user = $user;
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->branch = $branch;
        $this->warehouse = $warehouse;
        $this->device = $device;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null
            && $this->membership !== null
            && $this->branch !== null
            && $this->warehouse !== null;
    }

    public function user(): User
    {
        return $this->user ?? throw new RuntimeException('Tenant context has no authenticated user.');
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('Tenant context is not set.');
    }

    public function membership(): Membership
    {
        return $this->membership ?? throw new RuntimeException('Tenant context is not set.');
    }

    public function branch(): Branch
    {
        return $this->branch ?? throw new RuntimeException('Tenant context is not set.');
    }

    public function warehouse(): Warehouse
    {
        return $this->warehouse ?? throw new RuntimeException('Tenant context is not set.');
    }

    public function tenantId(): int
    {
        return (int) $this->tenant()->getKey();
    }

    public function membershipId(): int
    {
        return (int) $this->membership()->getKey();
    }

    public function branchId(): int
    {
        return (int) $this->branch()->getKey();
    }

    public function warehouseId(): int
    {
        return (int) $this->warehouse()->getKey();
    }

    public function userId(): int
    {
        return (int) $this->user()->getKey();
    }

    public function hasDevice(): bool
    {
        return $this->device !== null;
    }

    public function device(): Device
    {
        return $this->device ?? throw new RuntimeException('Tenant context has no device.');
    }

    public function deviceId(): int
    {
        return (int) $this->device()->getKey();
    }
}
