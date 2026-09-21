<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 32);
            $table->decimal('opening_cash', 20, 4)->default(0);
            $table->decimal('expected_cash', 20, 4)->nullable();
            $table->decimal('actual_cash', 20, 4)->nullable();
            $table->decimal('difference', 20, 4)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'membership_id', 'status']);
            $table->index(['tenant_id', 'device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
