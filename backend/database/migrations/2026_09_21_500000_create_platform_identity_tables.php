<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_permissions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->string('module', 40);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_roles', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('platform_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->foreignId('platform_permission_id')->constrained('platform_permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['platform_role_id', 'platform_permission_id']);
        });

        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status', 32);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedInteger('security_version')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_mfa_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('platform_user_roles', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignId('platform_role_id')->constrained('platform_roles')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['platform_user_id', 'platform_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_user_roles');
        Schema::dropIfExists('platform_users');
        Schema::dropIfExists('platform_role_permissions');
        Schema::dropIfExists('platform_roles');
        Schema::dropIfExists('platform_permissions');
    }
};
