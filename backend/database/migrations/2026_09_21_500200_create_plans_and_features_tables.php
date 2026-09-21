<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_catalog', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('module', 40);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32);
            $table->string('billing_interval', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('limit_key', 64);
            $table->unsignedInteger('value')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'limit_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('feature_catalog');
    }
};
