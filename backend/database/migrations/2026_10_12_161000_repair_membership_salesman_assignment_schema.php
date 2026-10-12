<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('memberships', 'salesman_party_profile_id')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->unsignedBigInteger('salesman_party_profile_id')->nullable();
            });
        }

        if (! Schema::hasColumn('memberships', 'salesman_start_time')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->time('salesman_start_time')->nullable();
            });
        }

        if (! Schema::hasColumn('memberships', 'salesman_end_time')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->time('salesman_end_time')->nullable();
            });
        }

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'memberships_salesman_party_profile_id_foreign'
    ) THEN
        ALTER TABLE memberships
        ADD CONSTRAINT memberships_salesman_party_profile_id_foreign
        FOREIGN KEY (salesman_party_profile_id)
        REFERENCES party_profiles(id)
        ON DELETE SET NULL;
    END IF;
END
$$;
SQL);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS memberships_tenant_id_salesman_party_profile_id_index
             ON memberships (tenant_id, salesman_party_profile_id)'
        );
    }

    public function down(): void
    {
        // Compatibility repair migration: do not remove potentially pre-existing
        // production columns, constraints, or indexes during rollback.
    }
};
