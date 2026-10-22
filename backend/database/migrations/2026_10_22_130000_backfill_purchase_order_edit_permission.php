<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')
            ->where('key', 'purchase_orders.edit')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'key' => 'purchase_orders.edit',
                'name' => 'Edit purchase orders',
                'module' => 'purchases',
                'description' => 'Edit saved purchase orders according to order status rules',
                'is_platform' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('roles')
            ->where('is_system', true)
            ->whereIn('code', ['owner', 'admin', 'manager', 'purchase'])
            ->orderBy('id')
            ->eachById(function (object $role) use ($permissionId, $now): void {
                DB::table('role_permissions')->insertOrIgnore([
                    'ulid' => (string) Str::ulid(),
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        // Data backfill is intentionally retained on rollback because the permission
        // may have pre-existed and may already be assigned intentionally.
    }
};
