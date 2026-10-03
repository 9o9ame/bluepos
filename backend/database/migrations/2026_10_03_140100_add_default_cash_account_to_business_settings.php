<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash/bank accounts a payment may be deposited into.
        Schema::table('business_settings', function (Blueprint $table) {
            $table->foreignId('default_cash_account_id')
                ->nullable()
                ->after('opening_balance_equity_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });

        // Contra side of a payment. Revenue/COGS posting is not built yet, so
        // sale settlement lands here until that journal exists.
        Schema::table('business_settings', function (Blueprint $table) {
            $table->foreignId('sales_clearing_account_id')
                ->nullable()
                ->after('default_cash_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_clearing_account_id');
            $table->dropConstrainedForeignId('default_cash_account_id');
        });
    }
};
