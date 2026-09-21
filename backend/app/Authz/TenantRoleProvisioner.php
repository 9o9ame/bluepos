<?php

namespace App\Authz;

use App\Enums\BranchAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Support\Str;

class TenantRoleProvisioner
{
    public function __construct(private readonly PermissionCatalogSync $catalogSync) {}

    /**
     * @return array<string, Role>
     */
    public function provision(Tenant $tenant): array
    {
        $this->catalogSync->ensure();

        $permissions = Permission::query()
            ->where('is_platform', false)
            ->get()
            ->keyBy('key');

        $roles = [];

        foreach (PermissionCatalogue::defaultRoles() as $definition) {
            $role = Role::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'branch_access' => BranchAccess::from($definition['branch_access']),
                    'is_system' => $definition['is_system'],
                    'is_active' => true,
                ],
            );

            $keys = PermissionCatalogue::keysForSystemRole($definition['code']);
            $permissionIds = [];

            foreach ($keys as $key) {
                $permission = $permissions->get($key);
                if ($permission) {
                    $permissionIds[] = $permission->id;
                }
            }

            $existing = $role->permissions()->pluck('permissions.id')->all();
            $missing = array_diff($permissionIds, $existing);

            foreach ($missing as $permissionId) {
                $role->permissions()->attach($permissionId, [
                    'ulid' => (string) Str::ulid(),
                ]);
            }

            $roles[$definition['code']] = $role->fresh(['permissions']);
        }

        return $roles;
    }
}
