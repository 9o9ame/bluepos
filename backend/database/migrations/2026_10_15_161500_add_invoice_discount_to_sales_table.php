<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('invoice_discount_percent', 12, 8)
                ->default(0)
                ->after('discount_amount');
            $table->decimal('invoice_discount_amount', 20, 4)
                ->default(0)
                ->after('invoice_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_discount_percent',
                'invoice_discount_amount',
            ]);
        });
    }
};
