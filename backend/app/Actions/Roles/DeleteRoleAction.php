<?php

namespace App\Actions\Roles;

use App\Exceptions\ApiException;
use App\Models\Role;

class DeleteRoleAction
{
    public function execute(Role $role): void
    {
        if ($role->is_system) {
            throw new ApiException('SYSTEM_ROLE_PROTECTED', 'System roles cannot be deleted.', 403);
        }

        if ($role->memberships()->exists()) {
            throw new ApiException('ROLE_IN_USE', 'The role is assigned to one or more users.', 409);
        }

        $role->permissions()->detach();
        $role->delete();
    }
}
