<?php

namespace App\Security;

use App\Authz\PermissionCatalogue;
use App\Models\Membership;

class PrivilegedAccess
{
    public function requiresMfaOnUnknownDevice(Membership $membership): bool
    {
        if ($membership->mfa_required === true) {
            return true;
        }

        return $membership->hasOwnerAuthority()
            || $membership->hasRoleCode(PermissionCatalogue::ADMIN);
    }
}
