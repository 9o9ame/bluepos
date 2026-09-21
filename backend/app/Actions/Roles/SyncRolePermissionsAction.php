<?php

namespace App\Actions\Roles;

use App\Authz\PermissionCatalogue;
use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncRolePermissionsAction
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @param  list<string>  $keys
     */
    public function execute(Role $role, array $keys): Role
    {
        if ($role->isOwnerRole()) {
            throw new ApiException('SYSTEM_ROLE_PROTECTED', 'Owner permissions cannot be changed.', 403);
        }

        $unique = array_values(array_unique($keys));

        foreach ($unique as $key) {
            if (! PermissionCatalogue::isTenantKey($key)) {
                throw ValidationException::withMessages([
                    'permissions' => 'Invalid or non-grantable permission: '.$key,
                ]);
            }

            if (! $this->permissions->canGrantPermissionKey($key)) {
                throw new ApiException('FORBIDDEN', 'You cannot grant a permission you are not allowed to manage.', 403);
            }
        }

        $permissionIds = Permission::query()
            ->whereIn('key', $unique)
            ->where('is_platform', false)
            ->pluck('id', 'key');

        if ($permissionIds->count() !== count($unique)) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more permissions were not found.',
            ]);
        }

        $current = $role->permissions()->pluck('permissions.id')->all();
        $desired = $permissionIds->values()->all();

        $detach = array_diff($current, $desired);
        if ($detach !== []) {
            $role->permissions()->detach($detach);
        }

        foreach (array_diff($desired, $current) as $permissionId) {
            $role->permissions()->attach($permissionId, [
                'ulid' => (string) Str::ulid(),
            ]);
        }

        return $role->fresh(['permissions']);
    }
}
