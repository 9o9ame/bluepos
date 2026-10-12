<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('fingerprint_ref', 64)->nullable()->after('salesman_end_time');
            $table->unique(['tenant_id', 'fingerprint_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'fingerprint_ref']);
            $table->dropColumn('fingerprint_ref');
        });
    }
};
