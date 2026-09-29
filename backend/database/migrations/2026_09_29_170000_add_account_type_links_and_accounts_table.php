<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'accounts.view' => 'View ledger accounts',
        'accounts.create' => 'Create ledger accounts',
        'accounts.edit' => 'Edit ledger accounts',
        'accounts.delete' => 'Deactivate ledger accounts',
        'accounts.manage' => 'Manage ledger accounts',
    ];

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('account_type_id')
                ->nullable()
                ->after('billing_address')
                ->constrained('account_types')
                ->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('account_type_id')
                ->nullable()
                ->after('billing_address')
                ->constrained('account_types')
                ->nullOnDelete();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 180);
            $table->text('address')->nullable();
            $table->foreignId('account_type_id')->constrained('account_types')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        $now = now();
        foreach ($this->permissions as $key => $name) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'ulid' => (string) Str::ulid(),
                    'key' => $key,
                    'name' => $name,
                    'module' => 'accounting',
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
            ->whereIn('key', ['accounts.view', 'accounts.create', 'accounts.edit'])
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
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('accounts');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_type_id');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_type_id');
        });
    }
};
