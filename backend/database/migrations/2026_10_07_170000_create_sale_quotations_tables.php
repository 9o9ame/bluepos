<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_quotations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('salesman_party_profile_id')
                ->nullable()
                ->constrained('party_profiles')
                ->restrictOnDelete();
            $table->string('document_number', 32);
            $table->date('quotation_date');
            $table->string('price_type', 20);
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->string('idempotency_key', 120);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'quotation_date']);
        });

        Schema::create('sale_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_scheme_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('barcode', 64)->nullable();
            $table->decimal('conversion_factor', 12, 8);
            $table->string('line_kind', 24);
            $table->decimal('quantity', 20, 6);
            $table->decimal('stock_quantity', 20, 6);
            $table->string('price_type', 20)->nullable();
            $table->decimal('unit_price', 20, 4);
            $table->decimal('gross_amount', 20, 4);
            $table->decimal('discount_percent', 12, 8);
            $table->decimal('discount_amount', 20, 4);
            $table->decimal('tax_percent', 12, 8);
            $table->decimal('tax_amount', 20, 4);
            $table->decimal('line_total', 20, 4);
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['sale_quotation_id', 'sort_order']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_quotation_items');
        Schema::dropIfExists('sale_quotations');
    }
};
