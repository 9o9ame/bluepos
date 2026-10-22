<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_revisions', function (Blueprint $table): void {
            $table->string('idempotency_key', 120)->nullable()->after('reason');
            $table->unique(
                ['tenant_id', 'document_type', 'idempotency_key'],
                'document_revisions_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_revisions', function (Blueprint $table): void {
            $table->dropUnique('document_revisions_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
