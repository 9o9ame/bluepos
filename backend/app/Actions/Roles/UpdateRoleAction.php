<?php

namespace App\Actions\Roles;

use App\Exceptions\ApiException;
use App\Models\Role;

class UpdateRoleAction
{
    /**
     * @param  array{name?: string, description?: ?string, is_active?: bool, branch_access?: string}  $data
     */
    public function execute(Role $role, array $data): Role
    {
        if ($role->is_system && array_key_exists('name', $data) && $data['name'] !== $role->name) {
            throw new ApiException('SYSTEM_ROLE_PROTECTED', 'System roles cannot be renamed.', 403);
        }

        if ($role->is_system && isset($data['branch_access']) && $data['branch_access'] !== $role->branch_access->value) {
            throw new ApiException('SYSTEM_ROLE_PROTECTED', 'System role branch access cannot be changed.', 403);
        }

        $role->fill([
            'name' => $data['name'] ?? $role->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
            'is_active' => $data['is_active'] ?? $role->is_active,
        ]);

        if (! $role->is_system && isset($data['branch_access'])) {
            $role->branch_access = $data['branch_access'];
        }

        $role->save();

        return $role->fresh(['permissions']);
    }
}
