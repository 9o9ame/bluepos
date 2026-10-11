<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->foreignId('sales_revenue_account_id')
                ->nullable()
                ->after('purchase_clearing_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('inventory_account_id')
                ->nullable()
                ->after('sales_revenue_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('cogs_account_id')
                ->nullable()
                ->after('inventory_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cogs_account_id');
            $table->dropConstrainedForeignId('inventory_account_id');
            $table->dropConstrainedForeignId('sales_revenue_account_id');
        });
    }
};
