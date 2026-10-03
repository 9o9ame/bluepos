<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->string('status', 20)->default('posted');
            $table->date('sale_date');
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'sale_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            // sale = paid line, free_packaging = bag/box, free_scheme = scheme reward.
            $table->string('line_kind', 24)->default('sale');
            $table->foreignId('sale_scheme_id')->nullable()->constrained('sale_schemes')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id']);
            $table->index(['sale_id']);
            $table->index(['tenant_id', 'sale_scheme_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
