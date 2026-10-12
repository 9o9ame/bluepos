<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_queries', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('title', 160);
            $table->text('query_text');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'title']);
        });

        $permissionId = DB::table('permissions')
            ->where('key', 'database.query.read')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'key' => 'database.query.read',
                'name' => 'Run safe custom queries',
                'module' => 'database',
                'description' => 'Run tenant-scoped read-only custom queries',
                'is_platform' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')
            ->whereIn('code', ['owner', 'admin'])
            ->orderBy('id')
            ->each(function (object $role) use ($permissionId): void {
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
        Schema::dropIfExists('custom_queries');

        $permissionId = DB::table('permissions')
            ->where('key', 'database.query.read')
            ->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
