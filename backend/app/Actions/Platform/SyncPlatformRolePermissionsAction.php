<?php

namespace App\Actions\Platform;

use App\Models\Platform\PlatformPermission;
use App\Models\Platform\PlatformRole;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformContext;
use App\Platform\PlatformPrivilegeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncPlatformRolePermissionsAction
{
    public function __construct(
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformPrivilegeGuard $privileges,
        private readonly PlatformContext $context,
    ) {}

    /**
     * @param  list<string>  $keys
     */
    public function execute(PlatformRole $role, array $keys): PlatformRole
    {
        $this->catalog->ensure();
        $this->privileges->assertSystemRoleProtected($role, 'permission-edited');
        $unique = array_values(array_unique($keys));
        $this->privileges->assertCanGrantPermissions($this->context->user(), $unique);

        return DB::transaction(function () use ($role, $unique): PlatformRole {
            $role = PlatformRole::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->privileges->assertSystemRoleProtected($role, 'permission-edited');

            $permissionIds = PlatformPermission::query()->whereIn('key', $unique)->pluck('id', 'key');
            if ($permissionIds->count() !== count($unique)) {
                throw ValidationException::withMessages([
                    'permissions' => 'One or more permissions were not found in the system catalogue.',
                ]);
            }

            $previous = $role->permissions()->pluck('platform_permissions.key')->all();
            $current = $role->permissions()->pluck('platform_permissions.id')->all();
            $desired = $permissionIds->values()->all();

            $detach = array_diff($current, $desired);
            if ($detach !== []) {
                $role->permissions()->detach($detach);
            }
            foreach (array_diff($desired, $current) as $permissionId) {
                $role->permissions()->attach($permissionId, ['ulid' => (string) Str::ulid()]);
            }

            $this->audit->record('PLATFORM_ROLE_PERMISSIONS_CHANGED', [
                'resource_type' => 'platform_role',
                'resource_ulid' => $role->ulid,
                'code' => $role->code,
                'old_permissions' => array_values($previous),
                'new_permissions' => $unique,
            ]);

            return $role->fresh(['permissions']) ?? $role;
        });
    }
}
