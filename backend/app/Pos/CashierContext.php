<?php

namespace App\Pos;

use App\Models\CashierShift;
use App\Models\Device;
use App\Models\Membership;
use App\Tenancy\TenantContext;

/**
 * Future Sales must require this context: session + approved device + membership + branch + open shift.
 */
final class CashierContext
{
    public function __construct(
        public readonly TenantContext $tenantContext,
        public readonly ?Device $device,
        public readonly ?CashierShift $shift,
    ) {}

    public function membership(): Membership
    {
        return $this->tenantContext->membership();
    }

    public function allowsPos(): bool
    {
        return $this->tenantContext->hasTenant()
            && $this->device !== null
            && $this->device->isActive()
            && $this->shift !== null
            && $this->shift->isOpen();
    }
}
