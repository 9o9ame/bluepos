<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('price_type', 32)->default('retail');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable();
            $table->decimal('conversion_factor', 12, 8)->default(1);
            $table->decimal('stock_quantity', 20, 6)->default(0);
            $table->string('price_type', 32)->nullable();
        });

        DB::statement('UPDATE sale_items SET stock_quantity = quantity');
        DB::statement("UPDATE sale_items SET price_type = 'retail' WHERE line_kind = 'sale'");
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn([
                'barcode',
                'conversion_factor',
                'stock_quantity',
                'price_type',
            ]);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('price_type');
        });
    }
};
