<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['suppliers', 'customers', 'accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $after = $tableName === 'accounts' ? 'address' : 'billing_address';
                $table->string('area', 120)->nullable()->after($after);
                $table->boolean('invoice_restricted')->default(false)->after('area');
                $table->decimal('credit_limit_amount', 20, 4)->default(0)->after('invoice_restricted');
                $table->unsignedInteger('credit_limit_days')->default(0)->after('credit_limit_amount');
            });
        }
    }

    public function down(): void
    {
        foreach (['suppliers', 'customers', 'accounts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn([
                    'area',
                    'invoice_restricted',
                    'credit_limit_amount',
                    'credit_limit_days',
                ]);
            });
        }
    }
};
