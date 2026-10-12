<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('key', 'inventory.recalculate')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'key' => 'inventory.recalculate',
                'name' => 'Recalculate stock',
                'module' => 'inventory',
                'description' => 'Repair cached stock balances from immutable stock movements',
                'is_platform' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')
            ->whereIn('code', ['owner', 'admin'])
            ->orderBy('id')
            ->eachById(function (object $role) use ($permissionId): void {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'ulid' => (string) Str::ulid(),
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('key', 'inventory.recalculate')
            ->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
