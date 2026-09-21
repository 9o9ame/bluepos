<?php

namespace App\Policies;

use App\Authz\PermissionService;
use App\Models\AuthSessionRecord;
use App\Models\User;
use App\Tenancy\TenantContext;

class AuthSessionRecordPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can('security.sessions.view');
    }

    public function revoke(User $user, AuthSessionRecord $record): bool
    {
        if (! $this->tenantContext->hasTenant()) {
            return false;
        }

        if ((int) $record->user_id === $this->tenantContext->userId()) {
            return true;
        }

        return $this->permissions->can('security.sessions.revoke')
            && (int) $record->tenant_id === $this->tenantContext->tenantId();
    }
}
