<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('laravel_session_id', 128);
            $table->unsignedInteger('security_version');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 180)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'membership_id', 'revoked_at']);
            $table->index(['laravel_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
