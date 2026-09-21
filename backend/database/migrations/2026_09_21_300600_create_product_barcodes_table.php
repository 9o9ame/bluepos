<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('barcode', 64);
            $table->decimal('conversion_factor', 12, 8);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'product_id']);
        });

        DB::statement('CREATE UNIQUE INDEX product_barcodes_one_primary_per_product ON product_barcodes (product_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
