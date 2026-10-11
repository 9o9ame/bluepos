<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->foreignId('sales_tax_payable_account_id')
                ->nullable()
                ->after('sales_revenue_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_tax_payable_account_id');
        });
    }
};
