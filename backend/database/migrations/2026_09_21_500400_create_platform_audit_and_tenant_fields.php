<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('actor_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->string('event', 80);
            $table->string('resource_type', 64)->nullable();
            $table->char('resource_ulid', 26)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 180)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event', 'occurred_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->unsignedInteger('security_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'security_version']);
        });
        Schema::dropIfExists('platform_audit_logs');
    }
};
