<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->string('supplier_invoice_number', 100)->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('freight_amount', 20, 4)->default(0);
            $table->decimal('other_charges', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'invoice_date']);
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'warehouse_id']);
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('conversion_factor', 12, 8)->default(1);
            $table->decimal('base_quantity', 20, 6);
            $table->decimal('unit_cost', 20, 4);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);
            $table->string('supplier_product_code', 100)->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id']);
            $table->index(['purchase_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');
    }
};
