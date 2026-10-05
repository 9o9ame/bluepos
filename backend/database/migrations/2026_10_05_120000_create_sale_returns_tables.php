<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('salesman_party_profile_id')->nullable()->constrained('party_profiles')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->date('return_date');
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->decimal('refund_amount', 20, 4)->default(0);
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'return_date']);
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('line_kind', 24);
            $table->string('barcode', 64)->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('conversion_factor', 12, 8);
            $table->decimal('stock_quantity', 20, 6);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('gross_amount', 20, 4);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);
            $table->string('reason', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['sale_return_id', 'sale_item_id'], 'sr_lines_return_sale_item_unique');
            $table->index(['tenant_id', 'product_id']);
            $table->index(['sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
