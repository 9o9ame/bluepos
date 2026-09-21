<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 32);
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('tenant_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->boolean('enabled');
            $table->string('reason');
            $table->foreignId('actor_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'feature_key']);
        });

        Schema::create('tenant_limit_overrides', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('limit_key', 64);
            $table->unsignedInteger('value')->nullable();
            $table->string('reason');
            $table->foreignId('actor_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'limit_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_limit_overrides');
        Schema::dropIfExists('tenant_feature_overrides');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
