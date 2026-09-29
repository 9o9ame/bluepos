<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('account_type_id')
                ->constrained('suppliers')
                ->nullOnDelete();
            $table->foreignId('customer_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('customers')
                ->nullOnDelete();

            $table->unique(['tenant_id', 'supplier_id']);
            $table->unique(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'supplier_id']);
            $table->dropUnique(['tenant_id', 'customer_id']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
