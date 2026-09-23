<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->default(0);
            $table->decimal('average_cost', 20, 4)->nullable();
            $table->decimal('stock_value', 20, 4)->nullable();
            $table->foreignId('last_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'branch_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
