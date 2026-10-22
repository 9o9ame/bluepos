<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, array{name:string,module:string,description:string,roles:list<string>}> */
    private array $permissions = [
        'sales.amend_posted' => [
            'name' => 'Amend posted sales',
            'module' => 'sales',
            'description' => 'Create controlled revisions of posted sales without mutating posted stock or journals',
            'roles' => ['owner', 'admin'],
        ],
        'sales.quotation.edit' => [
            'name' => 'Edit sale quotations',
            'module' => 'sales',
            'description' => 'Edit saved quotations and estimates before conversion or replacement',
            'roles' => ['owner', 'admin', 'manager', 'cashier'],
        ],
        'sales.return.amend_posted' => [
            'name' => 'Amend posted sale returns',
            'module' => 'sales',
            'description' => 'Create controlled revisions of posted sale returns',
            'roles' => ['owner', 'admin'],
        ],
        'purchases.amend_posted' => [
            'name' => 'Amend posted purchases',
            'module' => 'purchases',
            'description' => 'Create controlled revisions of posted purchase invoices',
            'roles' => ['owner', 'admin'],
        ],
        'purchase_orders.edit' => [
            'name' => 'Edit purchase orders',
            'module' => 'purchases',
            'description' => 'Edit saved purchase orders according to order status rules',
            'roles' => ['owner', 'admin', 'manager', 'purchase'],
        ],
        'purchase_returns.amend_posted' => [
            'name' => 'Amend posted purchase returns',
            'module' => 'purchases',
            'description' => 'Create controlled revisions of posted purchase returns',
            'roles' => ['owner', 'admin'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $key => $meta) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'ulid' => (string) Str::ulid(),
                    'key' => $key,
                    'name' => $meta['name'],
                    'module' => $meta['module'],
                    'description' => $meta['description'],
                    'is_platform' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionId = DB::table('permissions')->where('key', $key)->value('id');

            if (! $permissionId) {
                continue;
            }

            DB::table('roles')
                ->whereIn('code', $meta['roles'])
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
    }

    public function down(): void
    {
        $keys = array_keys($this->permissions);
        $permissionIds = DB::table('permissions')
            ->whereIn('key', $keys)
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
