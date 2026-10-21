<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_hold_items', function (Blueprint $table): void {
            $table->decimal('unit_price_override', 20, 4)->nullable()->after('quantity');
        });

        Schema::table('sale_quotation_items', function (Blueprint $table): void {
            $table->decimal('unit_price_override', 20, 4)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_quotation_items', function (Blueprint $table): void {
            $table->dropColumn('unit_price_override');
        });

        Schema::table('sale_hold_items', function (Blueprint $table): void {
            $table->dropColumn('unit_price_override');
        });
    }
};
