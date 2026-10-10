<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->date('order_date');
            $table->string('status', 20)->default('open');
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'order_date']);
            $table->index(['tenant_id', 'supplier_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('conversion_factor', 12, 8)->default(1);
            $table->decimal('base_quantity', 20, 6);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('gross_amount', 20, 4);
            $table->decimal('discount_percent', 12, 8)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'purchase_order_id']);
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('purchase_invoice_id')
                ->constrained('purchase_order_lines')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'purchase_order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_line_id']);
            $table->dropIndex(['tenant_id', 'purchase_order_line_id']);
            $table->dropColumn('purchase_order_line_id');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropIndex(['tenant_id', 'purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });

        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
