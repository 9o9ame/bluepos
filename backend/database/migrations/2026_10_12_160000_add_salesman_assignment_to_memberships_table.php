<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('salesman_party_profile_id')
                ->nullable()
                ->after('mfa_required')
                ->constrained('party_profiles')
                ->nullOnDelete();
            $table->time('salesman_start_time')->nullable()->after('salesman_party_profile_id');
            $table->time('salesman_end_time')->nullable()->after('salesman_start_time');

            $table->index(['tenant_id', 'salesman_party_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'salesman_party_profile_id']);
            $table->dropConstrainedForeignId('salesman_party_profile_id');
            $table->dropColumn(['salesman_start_time', 'salesman_end_time']);
        });
    }
};
