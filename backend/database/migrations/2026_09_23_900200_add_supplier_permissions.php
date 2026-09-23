<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'suppliers.view' => 'View suppliers',
        'suppliers.create' => 'Create suppliers',
        'suppliers.edit' => 'Edit suppliers',
        'suppliers.delete' => 'Deactivate suppliers',
        'suppliers.manage' => 'Manage suppliers',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $key => $name) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'ulid' => (string) Str::ulid(),
                    'key' => $key,
                    'name' => $name,
                    'module' => 'products',
                    'description' => null,
                    'is_platform' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $allPermissionIds = DB::table('permissions')
            ->whereIn('key', array_keys($this->permissions))
            ->pluck('id');
        $managerPermissionIds = DB::table('permissions')
            ->whereIn('key', [
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
            ])
            ->pluck('id');

        DB::table('roles')
            ->whereIn('code', ['owner', 'admin', 'manager'])
            ->orderBy('id')
            ->eachById(function (object $role) use ($allPermissionIds, $managerPermissionIds, $now): void {
                $permissionIds = $role->code === 'manager' ? $managerPermissionIds : $allPermissionIds;

                foreach ($permissionIds as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'ulid' => (string) Str::ulid(),
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys($this->permissions))
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
