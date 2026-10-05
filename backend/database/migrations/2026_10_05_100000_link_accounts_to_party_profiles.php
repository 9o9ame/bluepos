<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('party_profile_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('party_profiles')
                ->restrictOnDelete();
            $table->unique('party_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['party_profile_id']);
            $table->dropConstrainedForeignId('party_profile_id');
        });
    }
};
