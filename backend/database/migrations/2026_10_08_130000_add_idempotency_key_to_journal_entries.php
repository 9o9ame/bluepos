<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->after('voucher_number');
            $table->unique(['tenant_id', 'idempotency_key'], 'journal_entries_tenant_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_tenant_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
