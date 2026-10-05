<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_holds', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('salesman_party_profile_id')->nullable()->constrained('party_profiles')->restrictOnDelete();
            $table->date('sale_date')->nullable();
            $table->string('price_type', 20)->default('default');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'created_at']);
        });

        Schema::create('sale_hold_items', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('sale_hold_id')->constrained('sale_holds')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->foreignId('sale_scheme_id')->nullable()->constrained('sale_schemes')->restrictOnDelete();
            $table->string('line_kind', 24);
            $table->string('barcode', 64)->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('discount_percent', 12, 8)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'sale_hold_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_hold_items');
        Schema::dropIfExists('sale_holds');
    }
};
