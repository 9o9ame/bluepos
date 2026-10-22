<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 60);
            $table->ulid('document_ulid');
            $table->unsignedInteger('revision_number');
            $table->string('reason', 500);
            $table->jsonb('before_snapshot');
            $table->jsonb('after_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'document_type', 'document_ulid', 'revision_number'],
                'document_revisions_unique_revision'
            );
            $table->index(
                ['tenant_id', 'document_type', 'document_ulid'],
                'document_revisions_document_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
