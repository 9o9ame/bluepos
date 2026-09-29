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
        'coa.view' => 'View chart of accounts masters',
        'coa.create' => 'Create chart of accounts masters',
        'coa.edit' => 'Edit chart of accounts masters',
        'coa.delete' => 'Deactivate chart of accounts masters',
        'coa.manage' => 'Manage chart of accounts masters',
    ];

    public function up(): void
    {
        Schema::create('account_main_heads', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 180);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'sort_order']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('account_sub_heads', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('main_head_id')->constrained('account_main_heads')->restrictOnDelete();
            $table->string('name', 180);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'main_head_id', 'name']);
            $table->index(['tenant_id', 'sort_order']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('sub_head_id')->constrained('account_sub_heads')->restrictOnDelete();
            $table->string('name', 180);
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_bank')->default(false);
            $table->boolean('is_receivable')->default(false);
            $table->boolean('is_payable')->default(false);
            $table->string('pnl_grouping_label', 180)->nullable();
            $table->string('hint', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sub_head_id', 'name']);
            $table->index(['tenant_id', 'sort_order']);
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
            ->whereIn('key', ['coa.view', 'coa.create', 'coa.edit'])
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

        Schema::dropIfExists('account_types');
        Schema::dropIfExists('account_sub_heads');
        Schema::dropIfExists('account_main_heads');
    }
};
