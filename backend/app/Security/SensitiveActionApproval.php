<?php

namespace App\Security;

use App\Exceptions\ApiException;
use App\Models\Membership;

/**
 * Future Sales/Inventory/Accounting high-risk actions must record a manager
 * approval tied to the manager's identity. Shared manager passwords are forbidden.
 *
 * Cashier must not approve their own restricted action.
 */
class SensitiveActionApproval
{
    public function assertApprover(Membership $requester, Membership $approver, string $permission): void
    {
        if ((int) $requester->id === (int) $approver->id) {
            throw new ApiException('FORBIDDEN', 'You cannot approve your own restricted action.', 403);
        }

        if ((int) $requester->tenant_id !== (int) $approver->tenant_id) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        if (! $approver->hasOwnerAuthority() && ! $this->hasPermission($approver, $permission)) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }
    }

    private function hasPermission(Membership $membership, string $permission): bool
    {
        return $membership->roles()
            ->where('roles.tenant_id', $membership->tenant_id)
            ->where('roles.is_active', true)
            ->whereHas('permissions', fn ($query) => $query->where('permissions.key', $permission))
            ->exists();
    }
}
