<?php

namespace App\Accounting;

use App\Models\Membership;
use App\Tenancy\TenantContext;

class CashierTransactionLock
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Serialize cash-affecting writes for the authenticated cashier.
     *
     * This must be called from inside the caller's database transaction.
     * The future shift-close transaction will acquire the same membership row
     * lock before calculating expected cash, preventing a concurrent cash
     * posting by this cashier from slipping past the close snapshot.
     */
    public function lockCurrent(): Membership
    {
        return Membership::query()
            ->where('tenant_id', $this->tenantContext->tenantId())
            ->whereKey($this->tenantContext->membershipId())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
