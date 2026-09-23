<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->decimal('total_cost', 20, 4)->nullable();
            $table->string('reference_type', 60);
            $table->char('reference_ulid', 26)->nullable();
            $table->char('reference_line_ulid', 26)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'product_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_ulid']);
            $table->index(['movement_type']);
            $table->index(['occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
