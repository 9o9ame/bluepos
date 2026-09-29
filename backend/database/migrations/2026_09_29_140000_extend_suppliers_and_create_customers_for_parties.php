<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $customerPermissions = [
        'customers.view' => 'View customers',
        'customers.create' => 'Create customers',
        'customers.edit' => 'Edit customers',
        'customers.delete' => 'Deactivate customers',
        'customers.manage' => 'Manage customers',
    ];

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('deals_in', 180)->nullable()->after('name');
            $table->text('billing_address')->nullable()->after('address');
            $table->string('mobile', 64)->nullable()->after('contact_person');
            $table->string('mobile_secondary', 64)->nullable()->after('mobile');
            $table->string('phone_secondary', 64)->nullable()->after('phone');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 180);
            $table->string('deals_in', 180)->nullable();
            $table->string('contact_person', 180)->nullable();
            $table->string('mobile', 64)->nullable();
            $table->string('mobile_secondary', 64)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('phone_secondary', 64)->nullable();
            $table->string('email', 180)->nullable();
            $table->text('address')->nullable();
            $table->text('billing_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        $now = now();

        foreach ($this->customerPermissions as $key => $name) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'ulid' => (string) Str::ulid(),
                    'key' => $key,
                    'name' => $name,
                    'module' => 'parties',
                    'description' => null,
                    'is_platform' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $allPermissionIds = DB::table('permissions')
            ->whereIn('key', array_keys($this->customerPermissions))
            ->pluck('id');
        $managerPermissionIds = DB::table('permissions')
            ->whereIn('key', [
                'customers.view',
                'customers.create',
                'customers.edit',
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
            ->whereIn('key', array_keys($this->customerPermissions))
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('customers');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'deals_in',
                'billing_address',
                'mobile',
                'mobile_secondary',
                'phone_secondary',
            ]);
        });
    }
};
