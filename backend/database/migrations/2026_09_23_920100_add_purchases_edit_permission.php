<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'purchases.edit' => 'Edit purchase drafts',
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
                    'module' => 'purchases',
                    'description' => null,
                    'is_platform' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['purchases.edit', 'purchases.post'])
            ->pluck('id', 'key');

        DB::table('roles')
            ->whereIn('code', ['owner', 'admin', 'manager', 'purchase'])
            ->orderBy('id')
            ->eachById(function (object $role) use ($permissionIds, $now): void {
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
