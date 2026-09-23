<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->date('document_date');
            $table->string('status', 20)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'warehouse_id']);
        });

        Schema::create('inventory_opening_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('opening_balance_id')
                ->constrained('inventory_opening_balances')
                ->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 4);
            $table->decimal('total_cost', 20, 4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['opening_balance_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_opening_balance_lines');
        Schema::dropIfExists('inventory_opening_balances');
    }
};
