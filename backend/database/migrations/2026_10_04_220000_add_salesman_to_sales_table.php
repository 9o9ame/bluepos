<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('salesman_membership_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('memberships')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'salesman_membership_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'salesman_membership_id']);
            $table->dropConstrainedForeignId('salesman_membership_id');
        });
    }
};
