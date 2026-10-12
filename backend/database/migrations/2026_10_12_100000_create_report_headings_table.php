<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_headings', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('head_id', 40);
            $table->string('used_for', 120);
            $table->text('content_html')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'head_id']);
            $table->index(['tenant_id', 'used_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_headings');
    }
};
