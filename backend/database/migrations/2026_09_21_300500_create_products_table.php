<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('product_number', 64);
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->string('alternate_name')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('secondary_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('secondary_conversion_factor', 12, 8)->nullable();
            $table->decimal('tax_percent', 12, 8);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->decimal('reorder_level', 20, 6)->nullable();
            $table->decimal('minimum_stock', 20, 6)->nullable();
            $table->decimal('maximum_stock', 20, 6)->nullable();
            $table->string('rack_location', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 32);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_number']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'brand_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX products_tenant_sku_unique ON products (tenant_id, sku) WHERE sku IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
