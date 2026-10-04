<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('gross_amount', 20, 4)->default(0);
            $table->decimal('discount_percent', 12, 8)->default(0);
            $table->decimal('tax_percent', 12, 8)->default(0);
        });

        // Existing rows were created before discount/tax snapshots existed.
        // Their old line_total was quantity x unit_price, so it is also their
        // gross amount when both discount and tax were zero.
        DB::statement('UPDATE sale_items SET gross_amount = line_total');
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn([
                'gross_amount',
                'discount_percent',
                'tax_percent',
            ]);
        });
    }
};
