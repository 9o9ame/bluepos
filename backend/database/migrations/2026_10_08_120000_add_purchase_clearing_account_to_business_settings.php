<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->foreignId('purchase_clearing_account_id')
                ->nullable()
                ->after('sales_clearing_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_clearing_account_id');
        });
    }
};
