<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->foreignId('settlement_account_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('sale_id')->nullable()->change();
        });

        Schema::table('sale_return_lines', function (Blueprint $table): void {
            $table->foreignId('sale_item_id')->nullable()->change();
            $table->decimal('discount_percent', 12, 8)->default(0)->after('gross_amount');
            $table->decimal('tax_percent', 12, 8)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_lines', function (Blueprint $table): void {
            $table->dropColumn(['discount_percent', 'tax_percent']);
            $table->foreignId('sale_item_id')->nullable(false)->change();
        });

        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settlement_account_id');
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};
