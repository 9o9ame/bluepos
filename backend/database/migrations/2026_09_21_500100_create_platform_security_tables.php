<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_devices', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->string('name');
            $table->string('status', 32);
            $table->string('credential_hash');
            $table->timestamp('registered_at');
            $table->timestamp('trusted_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignId('platform_device_id')->nullable()->constrained('platform_devices')->nullOnDelete();
            $table->string('method', 32);
            $table->string('purpose', 32);
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignId('platform_device_id')->nullable()->constrained('platform_devices')->nullOnDelete();
            $table->string('laravel_session_id', 64)->nullable();
            $table->unsignedInteger('security_version');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 180)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sessions');
        Schema::dropIfExists('platform_mfa_challenges');
        Schema::dropIfExists('platform_devices');
    }
};
