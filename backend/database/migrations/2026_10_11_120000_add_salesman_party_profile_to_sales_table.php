<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some existing BluePOS databases already have this column from the
        // earlier Sales salesman work even though the checked-in migration
        // history only contains salesman_membership_id. Do not fail or rewrite
        // an existing persisted column.
        if (Schema::hasColumn('sales', 'salesman_party_profile_id')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('salesman_party_profile_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('party_profiles')
                ->nullOnDelete();

            $table->index(['tenant_id', 'salesman_party_profile_id']);
        });
    }

    public function down(): void
    {
        // Compatibility migration: an existing installation may have owned
        // this column before this migration was recorded. Never drop possibly
        // pre-existing persisted salesman data during rollback.
    }
};
